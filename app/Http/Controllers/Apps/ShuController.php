<?php

namespace App\Http\Controllers\Apps;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ExternalSale;
use App\Models\MemberContribution;
use App\Models\ShuPeriod;
use App\Services\Cooperative\ExternalSalesImportService;
use App\Services\Cooperative\NameMatcher;
use App\Services\Cooperative\ShuCalculator;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class ShuController extends Controller
{
    public function __construct(
        private readonly ShuCalculator $calculator,
        private readonly ExternalSalesImportService $salesImport,
    ) {}

    public function index()
    {
        $periods = ShuPeriod::query()
            ->withCount('allocations')
            ->withSum('allocations', 'total_rounded')
            ->orderByDesc('year')
            ->get();

        return Inertia::render('Dashboard/Cooperative/Shu/Index', [
            'periods' => $periods,
            'suggestedYear' => now()->year - 1,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100', 'unique:shu_periods,year'],
        ]);

        // Pakai pengaturan tahun sebelumnya bila ada, supaya user tidak mengisi ulang.
        $previous = ShuPeriod::where('year', '<', $data['year'])->orderByDesc('year')->first();
        $period = ShuPeriod::create([
            'year' => $data['year'],
            'total_shu' => 0,
            'base_per_person' => $previous->base_per_person ?? 100000,
            'base_requires_savings' => $previous->base_requires_savings ?? true,
            'base_for_ppnpn' => $previous->base_for_ppnpn ?? true,
            'base_mode' => $previous->base_mode ?? 'full',
            'board_amount' => $previous->board_amount ?? 0,
            'board_members' => $previous->board_members ?? [],
            'rounding_unit' => $previous->rounding_unit ?? 1000,
            // Bawaan: simpanan 40%, jasa usaha 60% (belanja 40 + pembiayaan dana 10 + barang 10).
            'portion_savings' => $previous->portion_savings ?? 40,
            'portion_purchases' => $previous->portion_purchases ?? 40,
            'portion_loans' => 0,
            'portion_financing_dana' => $previous->portion_financing_dana ?? 10,
            'portion_financing_barang' => $previous->portion_financing_barang ?? 10,
            'savings_types' => $previous->savings_types ?? MemberContribution::SAVINGS_TYPES,
            'savings_period' => $previous->savings_period ?? 'year',
            'sales_source' => $previous->sales_source ?? 'pos',
            'rounding' => $previous->rounding ?? 'round',
            'excluded_customer_ids' => $previous->excluded_customer_ids ?? [],
        ]);

        return to_route('shu.show', $period);
    }

    public function show(ShuPeriod $shu)
    {
        $allocations = $shu->allocations()
            ->with('customer:id,name,nip,employee_type,employment_status')
            ->orderByDesc('total')
            ->get();

        $unmatched = $shu->sales_source === 'excel'
            ? ExternalSale::where('year', $shu->year)
                ->where(fn ($q) => $q->whereNull('customer_id')->orWhereHas('customer', fn ($c) => $c->whereNull('employee_type')))
                ->selectRaw('buyer_name, SUM(amount) as total, COUNT(*) as days')
                ->groupBy('buyer_name')->orderByDesc('total')->get()
            : collect();
        if ($unmatched->isNotEmpty()) {
            $matcher = new NameMatcher;
            $unmatched->transform(fn ($row) => $row->setAttribute('suggestions', $matcher->suggestions($row->buyer_name)));
        }

        $excelSales = ExternalSale::where('year', $shu->year);
        $summary = $this->calculator->summary($shu);

        return Inertia::render('Dashboard/Cooperative/Shu/Show', [
            'period' => $shu,
            'allocations' => $allocations,
            'adjustments' => $shu->adjustments()->with('customer:id,name,nip,employee_type')->get(),
            'rules' => $this->rules($shu),
            'buyersWithoutDues' => $this->namesFor($summary['buyers_without_dues']),
            'stats' => collect($summary)->except('buyers_without_dues')->all() + [
                'excel_rows' => (clone $excelSales)->count(),
                'excel_total' => (int) (clone $excelSales)->sum('amount'),
                'distributed' => (float) $allocations->sum('total'),
                'distributed_rounded' => (int) $allocations->sum('total_rounded'),
            ],
            'unmatched' => $unmatched,
            'employees' => Customer::employees()->orderBy('name')->get(['id', 'name', 'nip', 'employee_type']),
            'typeLabels' => MemberContribution::TYPE_LABELS,
        ]);
    }

    public function update(Request $request, ShuPeriod $shu)
    {
        if ($shu->isFinal()) {
            return back()->with('error', 'SHU sudah final. Buka kunci dulu untuk mengubah pengaturan.');
        }

        $data = $request->validate([
            'total_shu' => ['required', 'integer', 'min:0'],
            'base_per_person' => ['required', 'integer', 'min:0'],
            'base_requires_savings' => ['required', 'boolean'],
            'base_for_ppnpn' => ['required', 'boolean'],
            'base_mode' => ['required', Rule::in(['full', 'monthly'])],
            'board_amount' => ['nullable', 'integer', 'min:0'],
            'board_members' => ['nullable', 'array'],
            'board_members.*.customer_id' => ['required', 'integer', 'exists:customers,id'],
            'board_members.*.months' => ['required', 'integer', 'min:1', 'max:12'],
            'portion_savings' => ['required', 'numeric', 'min:0', 'max:100'],
            'portion_purchases' => ['required', 'numeric', 'min:0', 'max:100'],
            'portion_financing_dana' => ['required', 'numeric', 'min:0', 'max:100'],
            'portion_financing_barang' => ['required', 'numeric', 'min:0', 'max:100'],
            'savings_types' => ['required', 'array', 'min:1'],
            'savings_types.*' => [Rule::in(MemberContribution::SAVINGS_TYPES)],
            'savings_period' => ['required', Rule::in(['year', 'all_time'])],
            'sales_source' => ['required', Rule::in(['pos', 'excel'])],
            'rounding' => ['required', Rule::in(['round', 'ceil', 'floor', 'none'])],
            'rounding_unit' => ['required', 'integer', Rule::in([1, 100, 500, 1000, 5000, 10000])],
            'excluded_customer_ids' => ['nullable', 'array'],
            'excluded_customer_ids.*' => ['integer', 'exists:customers,id'],
        ]);

        if (abs($data['portion_savings'] + $data['portion_purchases'] + $data['portion_financing_dana'] + $data['portion_financing_barang'] - 100) > 0.001) {
            return back()->withErrors(['portion_savings' => 'Total porsi simpanan + belanja + pembiayaan dana + pembiayaan barang harus 100%.']);
        }
        $data['portion_loans'] = 0;
        $data['board_amount'] = (int) ($data['board_amount'] ?? 0);
        $data['board_members'] = array_values($data['board_members'] ?? []);

        $data['excluded_customer_ids'] = array_values(array_unique(array_map('intval', $data['excluded_customer_ids'] ?? [])));
        $shu->update($data);

        return back()->with('success', 'Pengaturan SHU disimpan. Klik "Hitung SHU" untuk memperbarui hasil.');
    }

    public function calculate(ShuPeriod $shu)
    {
        try {
            $result = $this->calculator->calculate($shu);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "SHU {$shu->year} dihitung: {$result['recipients']} penerima ({$result['base_recipients']} dapat base SHU).");
    }

    public function saveAdjustment(Request $request, ShuPeriod $shu)
    {
        if ($shu->isFinal()) {
            return back()->with('error', 'SHU sudah final. Buka kunci dulu untuk mengubah koreksi.');
        }
        $data = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNotNull('employee_type')],
            'base_override' => ['nullable', 'integer', 'min:0'],
            'amount' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $data['amount'] = (int) ($data['amount'] ?? 0);
        $data['base_override'] ??= null;

        if ($data['base_override'] === null && $data['amount'] === 0) {
            $shu->adjustments()->where('customer_id', $data['customer_id'])->delete();

            return back()->with('success', 'Koreksi dihapus. Klik "Hitung SHU" untuk memperbarui hasil.');
        }

        $shu->adjustments()->updateOrCreate(['customer_id' => $data['customer_id']], $data);

        return back()->with('success', 'Koreksi disimpan. Klik "Hitung SHU" untuk memperbarui hasil.');
    }

    public function finalize(ShuPeriod $shu)
    {
        if (! $shu->calculated_at) {
            return back()->with('error', 'Hitung SHU dulu sebelum dikunci.');
        }
        $shu->update(['status' => 'final', 'finalized_at' => now()]);

        return back()->with('success', "SHU {$shu->year} dikunci (final).");
    }

    public function reopen(ShuPeriod $shu)
    {
        $shu->update(['status' => 'draft', 'finalized_at' => null]);

        return back()->with('success', "Kunci SHU {$shu->year} dibuka.");
    }

    public function destroy(ShuPeriod $shu)
    {
        if ($shu->isFinal()) {
            return back()->with('error', 'SHU final tidak bisa dihapus.');
        }
        $shu->delete();

        return to_route('shu.index')->with('success', 'Periode SHU dihapus.');
    }

    public function importSales(Request $request, ShuPeriod $shu)
    {
        set_time_limit(600); // file Excel besar (mis. rekap potongan 2020–2026)
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480']]);

        try {
            $result = $this->salesImport->import($request->file('file')->getRealPath(), $shu->year);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Penjualan Excel diimport: '.number_format($result['rows'], 0, ',', '.').' baris, total Rp '.number_format($result['total'], 0, ',', '.').". {$result['unmatched_names']} nama belum cocok dengan pegawai.");
    }

    public function mapSalesName(Request $request, ShuPeriod $shu)
    {
        $data = $request->validate([
            'buyer_name' => ['required', 'string'],
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNotNull('employee_type')],
        ]);
        $count = $this->salesImport->mapName($shu->year, $data['buyer_name'], (int) $data['customer_id']);

        return back()->with('success', "\"{$data['buyer_name']}\" dipetakan ({$count} baris). Nama ini otomatis cocok di import berikutnya.");
    }

    public function export(ShuPeriod $shu)
    {
        $rows = $shu->allocations()->with('customer')->orderByDesc('total')->get()->map(fn ($a) => [
            $a->customer?->nip,
            $a->customer?->name,
            $a->customer?->employee_type,
            $a->customer?->employment_status,
            $a->savings_amount,
            round($a->savings_share * 100, 4),
            $a->purchase_amount,
            round($a->purchase_share * 100, 4),
            $a->financing_dana_amount,
            round($a->financing_dana_share * 100, 4),
            $a->financing_barang_amount,
            round($a->financing_barang_share * 100, 4),
            $a->months_paid,
            $a->base_amount,
            $a->board_amount,
            round($a->shu_savings),
            round($a->shu_purchases),
            round($a->shu_financing_dana),
            round($a->shu_financing_barang),
            $a->adjustment_amount,
            $a->adjustment_note,
            round($a->total),
            $a->total_rounded,
        ])->all();

        return Excel::download(new ArrayExport(
            ['NIP', 'Nama', 'Jenis', 'Status', 'Iuran Koperasi', 'Porsi Iuran (%)', 'Belanja Toko', 'Porsi Belanja (%)', 'Pendapatan Pembiayaan Dana', 'Porsi Dana (%)', 'Pendapatan Pembiayaan Barang', 'Porsi Barang (%)', 'Bulan Bayar', 'SHU Dasar', 'SHU Pengurus Koperasi', 'SHU Iuran', 'SHU Belanja', 'SHU Pembiayaan Dana', 'SHU Pembiayaan Barang', 'Koreksi', 'Catatan Koreksi', 'Total SHU', 'Total Dibulatkan'],
            $rows,
            ['A' => '@'],
        ), "shu-{$shu->year}.xlsx");
    }

    /**
     * @param  array<int, array{customer_id: int, amount: int}>  $rows
     */
    private function namesFor(array $rows): array
    {
        $people = Customer::whereIn('id', array_column($rows, 'customer_id'))->get(['id', 'name', 'nip', 'employee_type', 'employment_status'])->keyBy('id');

        return collect($rows)->map(fn ($r) => $r + ['name' => $people[$r['customer_id']]->name ?? '-', 'nip' => $people[$r['customer_id']]->nip ?? null, 'employee_type' => $people[$r['customer_id']]->employee_type ?? null])
            ->sortByDesc('amount')->values()->all();
    }

    /**
     * Aturan SHU dalam bahasa manusia, sesuai pengaturan periode ini.
     *
     * @return array<int, string>
     */
    private function rules(ShuPeriod $period): array
    {
        $rupiah = fn ($v) => 'Rp '.number_format($v, 0, ',', '.');
        $types = implode(', ', array_map(fn ($t) => strtolower(MemberContribution::TYPE_LABELS[$t]), $period->savings_types ?? []));

        $unit = $rupiah($period->rounding_unit ?: 1000);
        $roundingText = [
            'round' => "dibulatkan ke {$unit} terdekat",
            'ceil' => "dibulatkan ke atas ke kelipatan {$unit}",
            'floor' => "dibulatkan ke bawah ke kelipatan {$unit}",
            'none' => 'tidak dibulatkan',
        ][$period->rounding] ?? '';
        $y = $period->year;
        $adjustments = $period->adjustments()->count();

        $boardCount = count($period->board_members ?? []);

        return array_values(array_filter([
            "SHU ini untuk tahun buku {$y} dan dibagikan pada tahun ".($y + 1).'.',
            "Yang ikut hanya pegawai yang aktif di tahun {$y} (masa aktif di Data Pegawai mencakup {$y}). Non-pegawai tidak mendapat SHU walaupun berbelanja.",
            "Penerima harus punya Iuran Koperasi, Belanja Toko, atau Pembiayaan Dana/Barang di tahun {$y}. Pegawai mutasi/pensiun di tahun {$y} tetap berhak.",
            'SHU dasar '.$rupiah($period->base_per_person).($period->base_mode === 'monthly' ? ' × (jumlah bulan membayar iuran ÷ 12)' : ' per orang')
                .($period->base_requires_savings ? " untuk anggota yang membayar Iuran Koperasi (pokok/wajib/sukarela) di tahun {$y}." : ' untuk semua pegawai aktif yang ikut (iuran, belanja, atau pembiayaan).')
                .($period->base_for_ppnpn ? ' PPNPN juga mendapat SHU dasar.' : ' PPNPN tidak mendapat SHU dasar.'),
            $period->base_requires_savings ? 'Pegawai yang berbelanja tetapi tidak membayar iuran tetap mendapat bagian Belanja Toko, tanpa SHU dasar.' : null,
            $boardCount > 0 && $period->board_amount > 0 ? "SHU Pengurus Koperasi {$rupiah($period->board_amount)} per orang per tahun (sebanding bulan bertugas) untuk {$boardCount} pengurus, dipotong dari SHU lebih dulu." : null,
            $adjustments > 0 ? "{$adjustments} koreksi manual (SHU dasar pengganti / tambahan nominal) diterapkan dan dipotong dari SHU lebih dulu." : null,
            'Sisa SHU (setelah SHU dasar, SHU pengurus koperasi, dan koreksi) dibagi: '.$period->portion_savings.'% Iuran Koperasi, '.$period->portion_purchases.'% Belanja Toko, '.$period->portion_financing_dana.'% Pembiayaan Dana, '.$period->portion_financing_barang.'% Pembiayaan Barang (total 100%).',
            "Bagian Iuran Koperasi dibagi sebanding jumlah {$types} ".($period->savings_period === 'all_time' ? "yang terkumpul sejak awal s.d. {$y}" : "yang dibayar selama tahun {$y}").' dibanding total semua anggota.',
            'Bagian Belanja Toko dibagi sebanding total belanja '.($period->sales_source === 'excel' ? '(impor Excel)' : 'di POS (dikurangi retur)')." selama tahun {$y} dibanding total belanja semua pegawai aktif.",
            "Bagian Pembiayaan Dana dibagi sebanding biaya formulir dari pembiayaan yang dicairkan di tahun {$y}.",
            "Bagian Pembiayaan Barang dibagi sebanding bagi hasil (dari cicilan yang dibayar di tahun {$y}) + biaya lain (pencairan di tahun {$y}). Cicilan/angsuran pokoknya tidak dihitung.",
            count($period->excluded_customer_ids ?? []) > 0 ? count($period->excluded_customer_ids).' nama dikecualikan dan tidak mendapat SHU.' : null,
            "Nilai SHU per orang {$roundingText}.",
        ]));
    }
}
