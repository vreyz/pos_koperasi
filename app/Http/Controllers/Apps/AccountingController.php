<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\KantinInvoice;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\Accounting\AutoJournalService;
use App\Services\Accounting\DefaultChart;
use App\Services\Accounting\EventCatalog;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\ReportService;
use App\Services\Consignment\DocumentNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Menu Akuntansi: jurnal, catat kejadian, buku besar, neraca saldo, laporan, COA, saldo awal. */
class AccountingController extends Controller
{
    public function __construct(
        private readonly JournalService $journal,
        private readonly EventCatalog $catalog,
        private readonly ReportService $reports,
    ) {}

    private function period(Request $request): array
    {
        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->query('to') ?: now()->endOfMonth()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()];
    }

    private function accountOptions(): array
    {
        return Account::orderBy('sort')->orderBy('code')->get(['code', 'name', 'group', 'is_cash', 'is_active'])->toArray();
    }

    private function employees(): array
    {
        return Customer::whereNotNull('employee_type')->orderBy('name')->get(['id', 'name', 'nip', 'employee_type', 'employment_status'])->toArray();
    }

    private function common(): array
    {
        return ['divisions' => DefaultChart::JOURNAL_DIVISIONS];
    }

    public function journals(Request $request)
    {
        [$from, $to] = $this->period($request);
        $entries = JournalEntry::query()
            ->whereBetween('date', [$from, $to])
            ->when($request->query('division'), fn ($q, $d) => $q->where('division', $d))
            ->when($request->query('event'), fn ($q, $e) => $q->where('event_code', $e))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('description', 'like', "%{$s}%")->orWhere('number', 'like', "%{$s}%")))
            ->with(['lines' => fn ($q) => $q->with(['account:id,code,name', 'customer:id,name'])->orderByDesc('debit'), 'creator:id,name', 'reversedBy:id,number,reversal_of_id', 'reversalOf:id,number'])
            ->orderByDesc('date')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        $entries->getCollection()->transform(function (JournalEntry $e) {
            $lines = $e->lines;
            $grouped = $lines->groupBy(fn ($l) => $l->account->code.'|'.($l->debit > 0 ? 'D' : 'K'))->map(fn ($g) => [
                'code' => $g->first()->account->code, 'name' => $g->first()->account->name,
                'debit' => (int) $g->sum('debit'), 'credit' => (int) $g->sum('credit'),
                'people' => $g->whereNotNull('customer_id')->count(),
                'detail' => $g->count() > 1 || $g->first()->customer_id ? $g->map(fn ($l) => ['customer' => $l->customer?->name, 'memo' => $l->memo, 'debit' => $l->debit, 'credit' => $l->credit])->values() : [],
                'memo' => $g->count() === 1 ? $g->first()->memo : null,
            ])->values();

            return [
                'id' => $e->id, 'number' => $e->number, 'date' => $e->date->toDateString(), 'division' => $e->division, 'event_code' => $e->event_code,
                'description' => $e->description, 'is_opening' => $e->is_opening, 'creator' => $e->creator?->name,
                'reversed_by' => $e->reversedBy?->number, 'reversal_of' => $e->reversalOf?->number,
                'total' => (int) $lines->sum('debit'), 'lines' => $grouped,
            ];
        });

        return Inertia::render('Dashboard/Accounting/Journals', [
            ...$this->common(),
            'entries' => $entries,
            'filters' => ['from' => $from, 'to' => $to, 'division' => $request->query('division'), 'event' => $request->query('event'), 'q' => $request->query('q')],
            'events' => collect($this->catalog->definitions())->map(fn ($d) => ['code' => $d['code'], 'label' => $d['label']])->values(),
        ]);
    }

    public function record(Request $request)
    {
        return Inertia::render('Dashboard/Accounting/Record', [
            ...$this->common(),
            'events' => array_values($this->catalog->definitions()),
            'accounts' => $this->accountOptions(),
            'employees' => $this->employees(),
            'kantinShare' => EventCatalog::kantinShare(),
            'autoFrom' => AutoJournalService::autoFrom(),
            'autoCodes' => EventCatalog::AUTO_CODES,
            'initialDivision' => $request->query('division'),
            'initialEvent' => $request->query('event'),
        ]);
    }

    private function buildFromRequest(Request $request): array
    {
        $data = $request->validate([
            'event' => ['required', 'string'],
            'date' => ['required', 'date'],
            'values' => ['array'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        if (isset(EventCatalog::AUTO_CODES[$data['event']]) && AutoJournalService::enabledFor($data['date'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['event' => 'Mulai '.Carbon::parse(AutoJournalService::autoFrom())->translatedFormat('j F Y')
                .' kejadian ini dijurnal otomatis dari menu '.EventCatalog::AUTO_CODES[$data['event']].'. Catat di menu tersebut supaya tidak dobel.']);
        }
        $entries = $this->catalog->build($data['event'], $data['values'] ?? [], $data['date']);
        foreach ($entries as &$entry) {
            $entry['lines'] = $this->journal->normalize($entry['lines']);
            $this->journal->assertBalanced($entry['lines']);
            if (! empty($data['note'])) {
                $entry['description'] .= ' - '.$data['note'];
            }
        }

        return [$data, $entries];
    }

    public function preview(Request $request)
    {
        [, $entries] = $this->buildFromRequest($request);
        $names = Account::pluck('name', 'code');
        $people = Customer::whereIn('id', collect($entries)->flatMap(fn ($e) => array_column($e['lines'], 'customer_id'))->filter()->unique())->pluck('name', 'id');

        return response()->json(['entries' => array_map(fn ($e) => [
            'description' => $e['description'],
            'lines' => array_map(fn ($l) => $l + ['name' => $names[$l['account']] ?? '?', 'customer' => $l['customer_id'] ? ($people[$l['customer_id']] ?? null) : null], $e['lines']),
        ], $entries)]);
    }

    public function storeEvent(Request $request)
    {
        [$data, $entries] = $this->buildFromRequest($request);
        $def = $this->catalog->definitions()[$data['event']];
        $numbers = DB::transaction(fn () => array_map(fn ($e) => $this->journal->post([
            'date' => $data['date'], 'description' => $e['description'], 'division' => $def['division'], 'event_code' => $data['event'],
        ], $e['lines'])->number, $entries));

        return back()->with('success', 'Jurnal tersimpan: '.implode(', ', $numbers));
    }

    public function manual()
    {
        return Inertia::render('Dashboard/Accounting/Manual', [
            ...$this->common(),
            'accounts' => $this->accountOptions(),
            'employees' => $this->employees(),
        ]);
    }

    public function storeManual(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:200'],
            'division' => ['nullable', Rule::in(array_keys(DefaultChart::JOURNAL_DIVISIONS))],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account' => ['required', 'string'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.customer_id' => ['nullable', 'integer'],
            'lines.*.memo' => ['nullable', 'string', 'max:150'],
        ]);
        $entry = $this->journal->post(['date' => $data['date'], 'description' => $data['description'], 'division' => $data['division'] ?? 'umum'], $data['lines']);

        return redirect()->route('accounting.journals', ['from' => $data['date'], 'to' => $data['date']])->with('success', "Jurnal {$entry->number} tersimpan.");
    }

    public function reverse(Request $request, JournalEntry $entry)
    {
        $data = $request->validate(['date' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:150']]);
        $rev = $this->journal->reverse($entry, $data['date'] ?? null, $data['reason'] ?? null);

        return back()->with('success', "Jurnal {$entry->number} dibalik dengan {$rev->number}.");
    }

    public function ledger(Request $request)
    {
        [$from, $to] = $this->period($request);
        $code = $request->query('account', '11102');
        $customer = $request->query('customer') ? (int) $request->query('customer') : null;

        return Inertia::render('Dashboard/Accounting/Ledger', [
            ...$this->common(),
            'accounts' => $this->accountOptions(),
            'employees' => $this->employees(),
            'filters' => ['from' => $from, 'to' => $to, 'account' => $code, 'customer' => $customer],
            'ledger' => Account::where('code', $code)->exists() ? $this->reports->ledger($code, $from, $to, $customer) : null,
        ]);
    }

    public function trialBalance(Request $request)
    {
        [$from, $to] = $this->period($request);

        return Inertia::render('Dashboard/Accounting/TrialBalance', [
            ...$this->common(),
            'filters' => ['from' => $from, 'to' => $to],
            'tb' => $this->reports->trialBalance($from, $to),
            'groups' => DefaultChart::GROUPS,
        ]);
    }

    public function reports(Request $request)
    {
        [$from, $to] = $this->period($request);
        $prior = Carbon::parse($from)->subDay()->toDateString();

        return Inertia::render('Dashboard/Accounting/Reports', [
            ...$this->common(),
            'filters' => ['from' => $from, 'to' => $to, 'tab' => $request->query('tab', 'neraca')],
            'balance' => $this->reports->balanceSheet($to),
            'balancePrior' => $this->reports->balanceSheet($prior),
            'income' => $this->reports->incomeStatement($from, $to),
            'equity' => $this->reports->equityChanges($from, $to),
            'cash' => $this->reports->cashFlow($from, $to),
            'store' => Setting::get('store_name', config('app.name')),
        ]);
    }

    public function accounts()
    {
        return Inertia::render('Dashboard/Accounting/Accounts', [
            ...$this->common(),
            'accounts' => Account::withCount('lines')->orderBy('sort')->orderBy('code')->get(),
            'groups' => DefaultChart::GROUPS,
            'reportLines' => array_values(array_unique(array_merge(array_column(DefaultChart::BALANCE_LINES, 0), array_column(DefaultChart::INCOME_LINES, 0)))),
            'pnlDivisions' => DefaultChart::DIVISIONS,
            'kantinShare' => EventCatalog::kantinShare(),
            'autoFrom' => AutoJournalService::autoFrom(),
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'kantinWarehouses' => app(AutoJournalService::class)->kantinWarehouseIds(),
        ]);
    }

    public function storeAccount(Request $request)
    {
        $data = $this->validateAccount($request);
        Account::create($data + ['sort' => ((int) Account::max('sort')) + 10]);

        return back()->with('success', "Akun {$data['code']} ditambahkan.");
    }

    public function updateAccount(Request $request, Account $account)
    {
        $data = $this->validateAccount($request, $account);
        if ($account->lines()->exists() && $data['code'] !== $account->code) {
            return back()->with('error', 'Kode akun yang sudah dipakai jurnal tidak bisa diubah.');
        }
        $account->update($data);

        return back()->with('success', "Akun {$account->code} diperbarui.");
    }

    private function validateAccount(Request $request, ?Account $account = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'regex:/^\d{3,10}$/', Rule::unique('accounts', 'code')->ignore($account?->id)],
            'name' => ['required', 'string', 'max:150'],
            'group' => ['required', Rule::in(array_keys(DefaultChart::GROUPS))],
            'normal_balance' => ['required', Rule::in(['D', 'K'])],
            'report_line' => ['required', 'string', 'max:100'],
            'division' => ['nullable', Rule::in(array_keys(DefaultChart::DIVISIONS))],
            'is_cash' => ['boolean'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:200'],
        ]);
        if (in_array($data['group'], ['pendapatan', 'beban_pokok', 'beban_usaha', 'pajak'], true)) {
            $data['division'] ??= 'umum';
            $data['is_cash'] = false;
        } else {
            $data['division'] = null;
        }

        return $data;
    }

    /**
     * Rekap kantin per bulan dari jurnal: penjualan (uang muka 21112), bagi hasil koperasi (43101),
     * bagian penjaga (21104 kredit), yang sudah ditransfer (21104 debit), sisa hutang, dan porsi aktual.
     * Sumbernya jurnal TK-15 (otomatis dari kasir outlet kantin atau dicatat dari rekap mingguan) & TK-16.
     */
    public function kantin(Request $request)
    {
        $year = (int) ($request->query('year') ?: now()->year);
        $from = "{$year}-01-01";
        $to = "{$year}-12-31";
        $lines = DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('a.code', ['21112', '43101', '21104'])
            ->whereBetween('e.date', [$from, $to])
            ->get(['e.id', 'e.date', 'e.number', 'e.description', 'e.event_code', 'a.code', 'l.debit', 'l.credit']);
        $opening = (int) DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', '21104')->where('e.date', '<', $from)
            ->selectRaw('COALESCE(SUM(l.credit - l.debit), 0) as s')->value('s');

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['month' => $m, 'sales' => 0, 'share' => 0, 'keeper' => 0, 'paid' => 0, 'balance' => 0];
        }
        foreach ($lines as $l) {
            $m = (int) substr((string) $l->date, 5, 2);
            match ($l->code) {
                '21112' => $months[$m]['sales'] += (int) $l->credit,
                '43101' => $months[$m]['share'] += (int) $l->credit - (int) $l->debit,
                '21104' => [$months[$m]['keeper'] += (int) $l->credit, $months[$m]['paid'] += (int) $l->debit],
            };
        }
        $run = $opening;
        foreach ($months as &$row) {
            $run += $row['keeper'] - $row['paid'];
            $row['balance'] = $run;
        }
        unset($row);
        $payments = $lines->where('code', '21104')->where('debit', '>', 0)->sortByDesc('date')->values()
            ->map(fn ($l) => ['id' => $l->id, 'date' => substr((string) $l->date, 0, 10), 'number' => $l->number, 'description' => $l->description, 'amount' => (int) $l->debit]);
        $kantinIds = app(AutoJournalService::class)->kantinWarehouseIds();

        return Inertia::render('Dashboard/Accounting/Kantin', [
            ...$this->common(),
            'year' => $year,
            'opening' => $opening,
            'months' => array_values($months),
            'payments' => $payments,
            'kantinShare' => EventCatalog::kantinShare(),
            'kantinWarehouses' => Warehouse::whereIn('id', $kantinIds)->orderBy('name')->pluck('name'),
            'autoFrom' => AutoJournalService::autoFrom(),
            'invoices' => KantinInvoice::where('year', $year)->orderByDesc('date')->orderByDesc('seq')->get(),
            'nextInvoiceNumber' => DocumentNumber::next('kantin', now()->toDateString()),
            'lastInvoice' => KantinInvoice::latest('id')->first(['payee_name', 'payee_bank', 'payee_account', 'signer', 'period_to']),
            'today' => now()->toDateString(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'kantin_share_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'auto_from' => ['nullable', 'date'],
            'kantin_warehouses' => ['nullable', 'array'],
            'kantin_warehouses.*' => ['integer'],
        ]);
        Setting::set(EventCatalog::KANTIN_SHARE_KEY, (string) $data['kantin_share_percent'], 'Persen bagi hasil koperasi dari penjualan kantin');
        if (! empty($data['auto_from'])) {
            Setting::set(AutoJournalService::FROM_KEY, Carbon::parse($data['auto_from'])->toDateString(), 'Tanggal mulai jurnal otomatis');
        }
        if (array_key_exists('kantin_warehouses', $data)) {
            Setting::set(AutoJournalService::KANTIN_KEY, implode(',', $data['kantin_warehouses'] ?? []), 'Gudang/outlet kantin untuk jurnal bagi hasil');
        }

        return back()->with('success', 'Pengaturan akuntansi disimpan.');
    }

    public function opening(Request $request)
    {
        $entry = JournalEntry::where('is_opening', true)->whereNull('reversal_of_id')->whereDoesntHave('reversedBy')->latest('date')->with('lines.account')->first();
        $values = [];
        foreach ($entry?->lines ?? [] as $l) {
            $values[$l->account->code] = ($values[$l->account->code] ?? 0) + $l->debit - $l->credit;
        }

        return Inertia::render('Dashboard/Accounting/Opening', [
            ...$this->common(),
            'accounts' => Account::where('group', '!=', 'pendapatan')->whereNotIn('group', ['beban_pokok', 'beban_usaha', 'pajak'])->orderBy('sort')->get(['code', 'name', 'group', 'normal_balance', 'report_line']),
            'entry' => $entry ? ['number' => $entry->number, 'date' => $entry->date->toDateString()] : null,
            'values' => $values,
            'groups' => DefaultChart::GROUPS,
        ]);
    }

    public function storeOpening(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'values' => ['required', 'array'],
            'values.*' => ['nullable', 'numeric'],
        ]);
        $lines = [];
        foreach ($data['values'] as $code => $value) {
            $v = (int) round((float) $value);
            if ($v) {
                $lines[] = ['account' => (string) $code, 'debit' => max($v, 0), 'credit' => max(-$v, 0)];
            }
        }
        $entry = DB::transaction(function () use ($data, $lines) {
            // Saldo awal boleh diganti selama belum ada tutup buku sesudahnya.
            $old = JournalEntry::where('is_opening', true)->get();
            if ($old->isNotEmpty() && JournalEntry::where('event_code', 'TB-01')->where('date', '>=', $old->min('date'))->exists()) {
                abort(422, 'Sudah ada tutup buku setelah saldo awal; koreksi lewat jurnal umum.');
            }
            $old->each(fn ($e) => $e->delete());

            return $this->journal->post(['date' => $data['date'], 'description' => 'Saldo awal per '.Carbon::parse($data['date'])->translatedFormat('j F Y'),
                'division' => 'tutup', 'is_opening' => true], $lines);
        });

        return back()->with('success', "Saldo awal tersimpan ({$entry->number}).");
    }

    public function sync(AutoJournalService $auto)
    {
        $result = $auto->syncAll();
        $s = $result['stats'];
        $msg = "Sinkronisasi selesai: {$s['dibuat']} dibuat, {$s['diganti']} diganti, {$s['dihapus']} dihapus, {$s['sama']} tetap.";
        if ($s['terkunci'] || $s['gagal']) {
            $msg .= " {$s['terkunci']} terkunci, {$s['gagal']} gagal: ".implode(' | ', array_slice($result['messages'], 0, 3));

            return back()->with('error', $msg);
        }

        if (! array_sum($s)) {
            $msg = 'Belum ada data untuk dijurnal otomatis (mulai '.Carbon::parse(AutoJournalService::autoFrom())->translatedFormat('j F Y').').';
        }

        return back()->with('success', $msg);
    }

    public function closeYear(Request $request)
    {
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']]);
        $result = $this->reports->closeYear((int) $data['year']);

        return back()->with('success', 'Tutup buku '.$data['year'].' selesai: SHU Rp'.number_format($result['shu'], 0, ',', '.').' ('.implode(', ', $result['entries']).').');
    }
}
