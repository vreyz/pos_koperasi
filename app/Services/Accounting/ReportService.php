<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Semua laporan dihitung dari baris jurnal: Neraca Saldo, Buku Besar, Neraca, PHU per divisi,
 * Laporan Perubahan Ekuitas, dan Arus Kas (metode langsung). Angka positif = debit.
 */
class ReportService
{
    /** Jurnal penutup tahun: tidak dihitung di PHU & mutasi ekuitas (isinya memindah SHU). */
    public const CLOSING_EVENTS = ['TB-01'];

    public function __construct(private readonly EventCatalog $catalog) {}

    /** Saldo satu akun sampai tanggal (debit positif). */
    public function accountBalance(string $code, string $asOf): int
    {
        return (int) JournalLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_lines.account_id')
            ->where('a.code', $code)->where('e.date', '<=', $asOf)
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit'));
    }

    /** @return array<int, array{debit:int,credit:int}> per account_id */
    private function sums(?string $from, ?string $to, array $excludeEvents = [], ?int $customerId = null): array
    {
        $q = JournalLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->when($from, fn ($q) => $q->where('e.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('e.date', '<=', $to))
            ->when($excludeEvents, fn ($q) => $q->where(fn ($w) => $w->whereNull('e.event_code')->orWhereNotIn('e.event_code', $excludeEvents)))
            ->when($customerId, fn ($q) => $q->where('journal_lines.customer_id', $customerId))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) as d, SUM(journal_lines.credit) as c');

        return $q->get()->mapWithKeys(fn ($r) => [$r->account_id => ['debit' => (int) $r->d, 'credit' => (int) $r->c]])->all();
    }

    private function dayBefore(string $date): string
    {
        return Carbon::parse($date)->subDay()->toDateString();
    }

    /** Neraca saldo periode. */
    public function trialBalance(string $from, string $to): array
    {
        $opening = $this->sums(null, $this->dayBefore($from));
        $moves = $this->sums($from, $to);
        $rows = [];
        $tot = ['opening' => 0, 'debit' => 0, 'credit' => 0, 'closing' => 0, 'closing_debit' => 0, 'closing_credit' => 0];
        foreach (Account::orderBy('sort')->orderBy('code')->get() as $a) {
            $o = ($opening[$a->id]['debit'] ?? 0) - ($opening[$a->id]['credit'] ?? 0);
            $d = $moves[$a->id]['debit'] ?? 0;
            $c = $moves[$a->id]['credit'] ?? 0;
            $close = $o + $d - $c;
            if (! $a->is_active && ! $o && ! $d && ! $c) {
                continue;
            }
            $rows[] = ['id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'group' => $a->group, 'report_line' => $a->report_line,
                'opening' => $o, 'debit' => $d, 'credit' => $c, 'closing' => $close];
            $tot['opening'] += $o;
            $tot['debit'] += $d;
            $tot['credit'] += $c;
            $tot['closing'] += $close;
            $tot['closing_debit'] += max($close, 0);
            $tot['closing_credit'] += max(-$close, 0);
        }

        return ['rows' => $rows, 'totals' => $tot];
    }

    /** Buku besar satu akun, bisa dibatasi per pegawai. */
    public function ledger(string $code, string $from, string $to, ?int $customerId = null): array
    {
        $account = Account::where('code', $code)->firstOrFail();
        $base = JournalLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->when($customerId, fn ($q) => $q->where('journal_lines.customer_id', $customerId));

        $opening = (int) (clone $base)->where('e.date', '<', $from)->sum(DB::raw('journal_lines.debit - journal_lines.credit'));
        $lines = (clone $base)->whereBetween('e.date', [$from, $to])
            ->leftJoin('customers as cu', 'cu.id', '=', 'journal_lines.customer_id')
            ->orderBy('e.date')->orderBy('e.id')->orderBy('journal_lines.id')
            ->get(['e.id as entry_id', 'e.number', 'e.date', 'e.event_code', 'e.description', 'journal_lines.debit', 'journal_lines.credit',
                'journal_lines.memo', 'journal_lines.customer_id', 'cu.name as customer_name']);

        $running = $opening;
        $rows = $lines->map(function ($l) use (&$running) {
            $running += (int) $l->debit - (int) $l->credit;

            return ['entry_id' => $l->entry_id, 'number' => $l->number, 'date' => Carbon::parse($l->date)->toDateString(), 'event_code' => $l->event_code,
                'description' => $l->description, 'memo' => $l->memo, 'customer' => $l->customer_name, 'customer_id' => $l->customer_id,
                'debit' => (int) $l->debit, 'credit' => (int) $l->credit, 'balance' => $running];
        })->all();

        // Rekap per pegawai untuk akun yang dipakai per pegawai (piutang rekap, pembiayaan, simpanan).
        $perCustomer = $customerId ? [] : JournalLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->join('customers as cu', 'cu.id', '=', 'journal_lines.customer_id')
            ->where('journal_lines.account_id', $account->id)->where('e.date', '<=', $to)
            ->groupBy('journal_lines.customer_id', 'cu.name')
            ->selectRaw('journal_lines.customer_id, cu.name, SUM(journal_lines.debit - journal_lines.credit) as balance')
            ->havingRaw('SUM(journal_lines.debit - journal_lines.credit) <> 0')
            ->orderBy('cu.name')->get()
            ->map(fn ($r) => ['customer_id' => $r->customer_id, 'name' => $r->name, 'balance' => (int) $r->balance])->all();

        return ['account' => $account->only(['code', 'name', 'normal_balance']), 'opening' => $opening, 'rows' => $rows,
            'closing' => $running, 'per_customer' => $perCustomer];
    }

    /** Saldo per pos laporan sampai tanggal. Dana Cadangan Tahun Berjalan termasuk SHU yang belum ditutup. */
    public function balanceSheet(string $asOf): array
    {
        $sums = $this->sums(null, $asOf);
        $lines = [];
        foreach (DefaultChart::BALANCE_LINES as [$line]) {
            $lines[$line] = 0;
        }
        foreach (Account::all() as $a) {
            $bal = ($sums[$a->id]['debit'] ?? 0) - ($sums[$a->id]['credit'] ?? 0);
            if (! $bal) {
                continue;
            }
            $line = $a->isIncomeStatement() ? DefaultChart::LINE_DCB : $a->report_line;
            $lines[$line] = ($lines[$line] ?? 0) + $bal;
        }
        $out = ['aset' => [], 'kewajiban' => [], 'ekuitas' => []];
        foreach (DefaultChart::BALANCE_LINES as [$line, $side]) {
            $v = $lines[$line] ?? 0;
            $out[$side][] = ['line' => $line, 'amount' => $side === 'aset' ? $v : -$v];
        }
        $sum = fn ($side) => array_sum(array_column($out[$side], 'amount'));
        $totals = ['aset' => $sum('aset'), 'kewajiban' => $sum('kewajiban'), 'ekuitas' => $sum('ekuitas')];
        $totals['selisih'] = $totals['aset'] - $totals['kewajiban'] - $totals['ekuitas'];

        return ['sections' => $out, 'totals' => $totals, 'shu_unclosed' => -array_sum(array_map(
            fn ($a) => ($sums[$a->id]['debit'] ?? 0) - ($sums[$a->id]['credit'] ?? 0),
            Account::whereIn('group', ['pendapatan', 'beban_pokok', 'beban_usaha', 'pajak'])->get()->all(),
        ))];
    }

    /** PHU per pos x divisi. Pendapatan positif, beban negatif. */
    public function incomeStatement(string $from, string $to): array
    {
        $sums = $this->sums($from, $to, self::CLOSING_EVENTS);
        $grid = [];
        foreach (Account::whereIn('group', ['pendapatan', 'beban_pokok', 'beban_usaha', 'pajak'])->get() as $a) {
            $v = -(($sums[$a->id]['debit'] ?? 0) - ($sums[$a->id]['credit'] ?? 0));
            $div = $a->division ?: 'umum';
            $grid[$a->report_line][$div] = ($grid[$a->report_line][$div] ?? 0) + $v;
        }
        $divs = array_keys(DefaultChart::DIVISIONS);
        $rows = [];
        $sections = ['usaha' => [], 'lain' => [], 'beban' => [], 'pajak' => []];
        foreach (DefaultChart::INCOME_LINES as [$line, $section]) {
            $vals = [];
            foreach ($divs as $d) {
                $vals[$d] = $grid[$line][$d] ?? 0;
            }
            $vals['total'] = array_sum($vals);
            $rows[] = ['line' => $line, 'section' => $section, 'values' => $vals];
            foreach ($vals as $k => $x) {
                $sections[$section][$k] = ($sections[$section][$k] ?? 0) + $x;
            }
        }
        $zero = array_fill_keys([...$divs, 'total'], 0);
        $usaha = $sections['usaha'] ?: $zero;
        $beforeTax = [];
        $shu = [];
        foreach (array_keys($zero) as $k) {
            $beforeTax[$k] = ($usaha[$k] ?? 0) + ($sections['lain'][$k] ?? 0) + ($sections['beban'][$k] ?? 0);
            $shu[$k] = $beforeTax[$k] + ($sections['pajak'][$k] ?? 0);
        }

        return ['divisions' => DefaultChart::DIVISIONS, 'rows' => $rows, 'gross' => $usaha, 'before_tax' => $beforeTax, 'shu' => $shu];
    }

    /** Laporan perubahan ekuitas per pos ekuitas. */
    public function equityChanges(string $from, string $to): array
    {
        $open = $this->balanceSheet($this->dayBefore($from));
        $close = $this->balanceSheet($to);
        $shu = $this->incomeStatement($from, $to)['shu']['total'];
        $equityLines = array_values(array_map(fn ($x) => $x[0], array_filter(DefaultChart::BALANCE_LINES, fn ($x) => $x[1] === 'ekuitas')));

        $moves = JournalLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_lines.account_id')
            ->whereBetween('e.date', [$from, $to])
            ->where('a.group', 'ekuitas')
            ->where(fn ($w) => $w->whereNull('e.event_code')->orWhereNotIn('e.event_code', ['TB-01', 'TB-05']))
            ->groupBy('a.report_line', 'e.event_code')
            ->selectRaw('a.report_line, e.event_code, SUM(journal_lines.credit) as c, SUM(journal_lines.debit) as d')
            ->get();

        $rowDefs = ['Saldo awal', 'Setoran / penambahan', 'Pengembalian / pengurangan', 'Pemindahan Dana Cadangan', 'Pembagian SHU', 'SHU periode ini', 'Saldo akhir'];
        $table = array_fill_keys($rowDefs, array_fill_keys($equityLines, 0));
        foreach ($open['sections']['ekuitas'] as $r) {
            $table['Saldo awal'][$r['line']] = $r['amount'];
        }
        foreach ($close['sections']['ekuitas'] as $r) {
            $table['Saldo akhir'][$r['line']] = $r['amount'];
        }
        foreach ($moves as $m) {
            $line = $m->report_line;
            $net = (int) $m->c - (int) $m->d;
            if ($m->event_code === 'TB-03') {
                $table['Pemindahan Dana Cadangan'][$line] += $net;
            } elseif ($m->event_code === 'TB-04') {
                $table['Pembagian SHU'][$line] += $net;
            } else {
                $table['Setoran / penambahan'][$line] += (int) $m->c;
                $table['Pengembalian / pengurangan'][$line] -= (int) $m->d;
            }
        }
        $table['SHU periode ini'][DefaultChart::LINE_DCB] = $shu;

        $check = 0;
        foreach ($equityLines as $line) {
            $sum = 0;
            foreach (array_slice($rowDefs, 0, 6) as $r) {
                $sum += $table[$r][$line];
            }
            $check += $sum - $table['Saldo akhir'][$line];
        }

        return ['columns' => $equityLines, 'rows' => array_map(fn ($r) => ['label' => $r, 'values' => $table[$r], 'total' => array_sum($table[$r])], $rowDefs), 'selisih' => $check];
    }

    /** Arus kas metode langsung dari baris akun kas, dikelompokkan menurut kejadian. */
    public function cashFlow(string $from, string $to): array
    {
        $cashIds = Account::where('is_cash', true)->pluck('id')->all();
        $flows = $this->catalog->flows();
        $entries = JournalEntry::query()->whereBetween('date', [$from, $to])->where('is_opening', false)
            ->whereHas('lines', fn ($q) => $q->whereIn('account_id', $cashIds))
            ->with('lines:id,journal_entry_id,account_id,debit,credit')->get();

        $groups = ['operasi' => [], 'investasi' => [], 'pendanaan' => []];
        foreach ($entries as $e) {
            $cashLines = $e->lines->whereIn('account_id', $cashIds);
            if ($cashLines->count() === $e->lines->count()) {
                continue; // pindah antar rekening kas
            }
            $net = (int) $cashLines->sum(fn ($l) => $l->debit - $l->credit);
            if (! $net) {
                continue;
            }
            [$group, $label] = $flows[$e->event_code] ?? EventCatalog::FLOW_JURNAL_UMUM;
            $groups[$group][$label] = ($groups[$group][$label] ?? 0) + $net;
        }
        $out = [];
        $change = 0;
        foreach ($groups as $g => $items) {
            $rows = [];
            foreach ($items as $label => $amount) {
                $rows[] = ['label' => $label, 'amount' => $amount];
            }
            $subtotal = array_sum($items);
            $change += $subtotal;
            $out[] = ['group' => $g, 'rows' => $rows, 'subtotal' => $subtotal];
        }
        $openingCash = $this->cashBalance($this->dayBefore($from), $cashIds);
        $closingCash = $this->cashBalance($to, $cashIds);

        return ['groups' => $out, 'change' => $change, 'opening' => $openingCash, 'closing' => $closingCash, 'selisih' => $openingCash + $change - $closingCash];
    }

    private function cashBalance(string $asOf, array $cashIds): int
    {
        return (int) JournalLine::query()->join('journal_entries as e', 'e.id', '=', 'journal_lines.journal_entry_id')
            ->whereIn('journal_lines.account_id', $cashIds)->where('e.date', '<=', $asOf)
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit'));
    }

    /**
     * Tutup buku: akun laba rugi tahun itu dipindah ke Dana Cadangan Tahun Berjalan (TB-01),
     * pembagian SHU & koreksi ditutup ke Dana Cadangan Tahun Lalu (TB-05).
     */
    public function closeYear(int $year): array
    {
        $date = "{$year}-12-31";
        $already = JournalEntry::where('event_code', 'TB-01')->whereDate('date', $date)->whereDoesntHave('reversedBy')->whereNull('reversal_of_id')->exists();
        if ($already) {
            throw ValidationException::withMessages(['year' => "Tahun {$year} sudah ditutup. Balik jurnal penutupnya dulu bila perlu menutup ulang."]);
        }
        $sums = $this->sums("{$year}-01-01", $date, self::CLOSING_EVENTS);
        $lines = [];
        $shu = 0;
        foreach (Account::whereIn('group', ['pendapatan', 'beban_pokok', 'beban_usaha', 'pajak'])->get() as $a) {
            $bal = ($sums[$a->id]['debit'] ?? 0) - ($sums[$a->id]['credit'] ?? 0);
            if (! $bal) {
                continue;
            }
            $lines[] = ['account' => $a->code, 'debit' => max(-$bal, 0), 'credit' => max($bal, 0)];
            $shu -= $bal;
        }
        $journal = app(JournalService::class);
        $posted = [];
        if ($lines) {
            $lines[] = ['account' => '33102', 'debit' => max(-$shu, 0), 'credit' => max($shu, 0)];
            $posted[] = $journal->post(['date' => $date, 'division' => 'tutup', 'event_code' => 'TB-01',
                'description' => "Tutup buku {$year}: SHU ke Dana Cadangan Tahun Berjalan"], $lines)->number;
        }
        $reserve = [];
        foreach (['33201', '33301'] as $code) {
            $bal = $this->accountBalance($code, $date);
            if ($bal) {
                $reserve[] = ['account' => $code, 'debit' => max(-$bal, 0), 'credit' => max($bal, 0)];
                $reserve[] = ['account' => '33101', 'debit' => max($bal, 0), 'credit' => max(-$bal, 0)];
            }
        }
        if ($reserve) {
            $posted[] = $journal->post(['date' => $date, 'division' => 'tutup', 'event_code' => 'TB-05',
                'description' => "Tutup buku {$year}: pembagian SHU & koreksi ke Dana Cadangan Tahun Lalu"], $reserve)->number;
        }

        return ['shu' => $shu, 'entries' => $posted];
    }
}
