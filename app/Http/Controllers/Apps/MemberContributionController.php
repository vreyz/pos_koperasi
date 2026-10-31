<?php

namespace App\Http\Controllers\Apps;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MemberContribution;
use App\Services\Cooperative\ContributionImportService;
use App\Services\Cooperative\ImportFileStore;
use App\Services\Cooperative\MemberSync;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class MemberContributionController extends Controller
{
    public function __construct(
        private readonly ContributionImportService $importService,
        private readonly ImportFileStore $files,
    ) {}

    public function index(Request $request)
    {
        $years = $this->years();
        $year = (int) $request->query('year', $years[0] ?? now()->year);
        $tab = $request->query('tab') === 'history' ? 'history' : 'recap';
        $customerId = $request->integer('customer_id') ?: null;

        $recap = null;
        $history = null;

        if ($tab === 'recap') {
            $recap = $this->recapQuery($year)
                ->when(! $request->boolean('all'), fn ($q) => $q->whereHas('contributions', fn ($c) => $c->where('period_year', $year)))
                ->orderBy('name')
                ->get();
        } else {
            // Satu baris per pegawai per jenis, kolom bulan Jan–Des; daftar catatan per bulan untuk dilihat/dihapus.
            $entries = MemberContribution::query()
                ->with('creator:id,name')
                ->where('period_year', $year)
                ->orderBy('period_month')
                ->orderBy('id')
                ->get(['id', 'customer_id', 'type', 'period_month', 'amount', 'source', 'source_name', 'notes', 'created_by']);
            $people = Customer::whereIn('id', $entries->pluck('customer_id')->unique())
                ->get(['id', 'name', 'nip', 'employee_type', 'employment_status'])
                ->keyBy('id');

            $history = $entries->groupBy(fn ($e) => $e->customer_id.'|'.$e->type)->map(function ($group) use ($people) {
                $first = $group->first();
                $person = $people->get($first->customer_id);
                $months = array_fill(1, 12, 0);
                $byMonth = [];
                foreach ($group as $entry) {
                    $months[$entry->period_month] += $entry->amount;
                    $byMonth[$entry->period_month][] = [
                        'id' => $entry->id,
                        'amount' => $entry->amount,
                        'source' => $entry->source,
                        'source_name' => $entry->source_name,
                        'notes' => $entry->notes,
                        'creator' => $entry->creator?->name,
                    ];
                }

                return [
                    'key' => $first->customer_id.'|'.$first->type,
                    'customer_id' => $first->customer_id,
                    'name' => $person?->name,
                    'nip' => $person?->nip,
                    'employee_type' => $person?->employee_type,
                    'employment_status' => $person?->employment_status,
                    'type' => $first->type,
                    'months' => $months,
                    'total' => array_sum($months),
                    'entries' => $byMonth,
                ];
            })->values();
        }

        $totals = MemberContribution::where('period_year', $year)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return Inertia::render('Dashboard/Cooperative/Savings/Index', [
            'tab' => $tab,
            'year' => $year,
            'years' => $years ?: [now()->year],
            'filters' => ['customer_id' => $customerId, 'all' => $request->boolean('all')],
            'recap' => $recap,
            'history' => $history,
            'totals' => $totals,
            'payers' => MemberContribution::where('period_year', $year)->whereIn('type', MemberContribution::SAVINGS_TYPES)->where('amount', '>', 0)->distinct()->count('customer_id'),
            'typeLabels' => MemberContribution::TYPE_LABELS,
            'employees' => Customer::employees()->orderBy('name')->get(['id', 'name', 'nip', 'employee_type', 'employment_status']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNotNull('employee_type')],
            'type' => ['required', Rule::in(MemberContribution::TYPES)],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'direction' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'integer', 'min:1'],
            'source' => ['required', Rule::in(['manual', 'potong_gaji', 'tunai', 'transfer'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if ($data['direction'] === 'out') {
            if ($data['type'] === MemberContribution::TYPE_ANGSURAN) {
                return back()->withErrors(['direction' => 'Angsuran pinjaman tidak bisa ditarik.']);
            }
            $balance = (int) MemberContribution::where('customer_id', $data['customer_id'])->where('type', $data['type'])->sum('amount');
            if ($data['amount'] > $balance) {
                return back()->withErrors(['amount' => 'Penarikan melebihi saldo '.MemberContribution::TYPE_LABELS[$data['type']].' (Rp '.number_format($balance, 0, ',', '.').').']);
            }
        }

        MemberContribution::create([
            'customer_id' => $data['customer_id'],
            'type' => $data['type'],
            'period_year' => $data['period_year'],
            'period_month' => $data['period_month'],
            'amount' => $data['direction'] === 'out' ? -$data['amount'] : $data['amount'],
            'source' => $data['source'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        app(MemberSync::class)->syncMembers();

        return back()->with('success', 'Data simpanan disimpan.');
    }

    public function destroy(MemberContribution $contribution)
    {
        $contribution->delete();

        return back()->with('success', 'Data simpanan dihapus.');
    }

    /**
     * Buat potongan bulanan (mis. simpanan wajib) untuk semua pegawai aktif
     * yang belum punya catatan jenis itu di periode tersebut.
     */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([MemberContribution::TYPE_WAJIB, MemberContribution::TYPE_POKOK])],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'amount' => ['required', 'integer', 'min:1'],
            'employee_types' => ['required', 'array', 'min:1'],
            'employee_types.*' => [Rule::in(Customer::EMPLOYEE_TYPES)],
        ]);

        $already = MemberContribution::where('type', $data['type'])
            ->when($data['type'] === MemberContribution::TYPE_POKOK, fn ($q) => $q, fn ($q) => $q->where('period_year', $data['period_year'])->where('period_month', $data['period_month']))
            ->pluck('customer_id');

        $targets = Customer::employees()
            ->whereIn('employee_type', $data['employee_types'])
            ->where('employment_status', 'AKTIF')
            ->whereNotIn('id', $already)
            ->pluck('id');

        $now = now();
        $rows = $targets->map(fn ($id) => [
            'customer_id' => $id,
            'type' => $data['type'],
            'period_year' => $data['period_year'],
            'period_month' => $data['period_month'],
            'amount' => $data['amount'],
            'source' => 'potong_gaji',
            'notes' => 'Generate otomatis',
            'created_by' => $request->user()->id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
        foreach (array_chunk($rows, 500) as $chunk) {
            MemberContribution::insert($chunk);
        }

        app(MemberSync::class)->syncMembers();

        return back()->with('success', count($rows).' potongan '.MemberContribution::TYPE_LABELS[$data['type']].' dibuat.');
    }

    public function import(Request $request)
    {
        set_time_limit(600); // file Excel besar (mis. rekap potongan 2020–2026)
        $token = $request->query('token');
        $path = $this->files->path('savings', $token);
        if ($token && ! $path) {
            return to_route('savings.import')->with('error', 'File pratinjau sudah kedaluwarsa. Upload ulang.');
        }

        $preview = null;
        if ($path) {
            try {
                $preview = $this->importService->preview($this->importService->parse($path)) + ['token' => $token];
            } catch (RuntimeException $e) {
                $this->files->forget('savings', $token);

                return to_route('savings.import')->with('error', $e->getMessage());
            }
        }

        return Inertia::render('Dashboard/Cooperative/Savings/Import', [
            'preview' => $preview,
            'employees' => $path ? Customer::employees()->orderBy('name')->get(['id', 'name', 'nip']) : [],
        ]);
    }

    public function previewImport(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480']]);
        $token = $this->files->put($request->file('file'), 'savings');

        return to_route('savings.import', ['token' => $token]);
    }

    public function commitImport(Request $request)
    {
        set_time_limit(600); // file Excel besar (mis. rekap potongan 2020–2026)
        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'mapping' => ['array'],
        ]);
        $path = $this->files->path('savings', $validated['token']);
        if (! $path) {
            return to_route('savings.import')->with('error', 'File pratinjau sudah kedaluwarsa. Upload ulang.');
        }

        $mapping = collect($validated['mapping'] ?? [])
            ->map(fn ($v) => $v === 'new' ? 'new' : (is_numeric($v) ? (int) $v : null))
            ->all();
        $validIds = Customer::employees()->whereIn('id', array_filter($mapping, 'is_int'))->pluck('id')->all();
        $mapping = array_map(fn ($v) => is_int($v) && ! in_array($v, $validIds, true) ? null : $v, $mapping);

        $summary = $this->importService->commit($this->importService->parse($path), $mapping, $request->user()->id);
        $this->files->forget('savings', $validated['token']);

        return to_route('savings.index')->with('success', "Import simpanan selesai: {$summary['saved']} catatan disimpan, {$summary['created_employees']} pegawai baru, {$summary['skipped_names']} nama dilewati.");
    }

    public function template()
    {
        return Excel::download(new ArrayExport(
            ['NIP', 'Nama Pegawai', 'Tahun', 'Bulan', 'Pokok', 'Wajib', 'Sukarela', 'Angsuran Koperasi'],
            [['199001012015031001', 'Contoh Pegawai', now()->year, 1, 50000, 100000, 0, 0]],
            ['A' => '@'],
        ), 'template-simpanan.xlsx');
    }

    public function export(Request $request)
    {
        $year = (int) $request->query('year', now()->year);
        $rows = $this->recapQuery($year)
            ->whereHas('contributions', fn ($c) => $c->where('period_year', $year))
            ->orderBy('name')
            ->get()
            ->map(fn (Customer $c) => [
                $c->nip, $c->name, $c->employee_type, $c->employment_status,
                (int) $c->pokok_total, (int) $c->wajib_total, (int) $c->sukarela_total,
                (int) $c->pokok_total + (int) $c->wajib_total + (int) $c->sukarela_total,
                (int) $c->angsuran_total, (int) $c->savings_balance,
            ])->all();

        return Excel::download(new ArrayExport(
            ['NIP', 'Nama', 'Jenis', 'Status', 'Pokok '.$year, 'Wajib '.$year, 'Sukarela '.$year, 'Total Simpanan '.$year, 'Angsuran '.$year, 'Saldo Simpanan s.d. '.$year],
            $rows,
            ['A' => '@'],
        ), "rekap-simpanan-{$year}.xlsx");
    }

    private function recapQuery(int $year)
    {
        $query = Customer::employees()->select(['id', 'name', 'nip', 'employee_type', 'employment_status']);
        foreach (MemberContribution::TYPES as $type) {
            $query->withSum(["contributions as {$type}_total" => fn ($q) => $q->where('type', $type)->where('period_year', $year)], 'amount');
        }

        return $query->withSum(['contributions as savings_balance' => fn ($q) => $q
            ->whereIn('type', MemberContribution::SAVINGS_TYPES)
            ->where('period_year', '<=', $year)], 'amount');
    }

    /**
     * @return array<int, int>
     */
    private function years(): array
    {
        return MemberContribution::query()->distinct()->orderByDesc('period_year')->pluck('period_year')->map(fn ($y) => (int) $y)->all();
    }
}
