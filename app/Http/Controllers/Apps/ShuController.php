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
            'portion_savings' => $previous->portion_savings ?? 70,
            'portion_purchases' => $previous->portion_purchases ?? 30,
            'portion_loans' => $previous->portion_loans ?? 0,
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
            ? ExternalSale::where('year', $shu->year)->whereNull('customer_id')
                ->selectRaw('buyer_name, SUM(amount) as total, COUNT(*) as days')
                ->groupBy('buyer_name')->orderByDesc('total')->get()
            : collect();
        if ($unmatched->isNotEmpty()) {
            $matcher = new NameMatcher;
            $unmatched->transform(fn ($row) => $row->setAttribute('suggestions', $matcher->suggestions($row->buyer_name)));
        }

        $excelSales = ExternalSale::where('year', $shu->year);
        $payers = $this->calculator->payersInYear($shu->year);
        $purchases = $this->calculator->purchases($shu);

        return Inertia::render('Dashboard/Cooperative/Shu/Show', [
            'period' => $shu,
            'allocations' => $allocations,
            'rules' => $this->rules($shu),
            'stats' => [
                'payers' => count($payers),
                'base_total' => count($payers) * $shu->base_per_person,
                'purchase_total' => array_sum($purchases),
                'purchase_buyers' => count($purchases),
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
            'portion_savings' => ['required', 'numeric', 'min:0', 'max:100'],
            'portion_purchases' => ['required', 'numeric', 'min:0', 'max:100'],
            'portion_loans' => ['required', 'numeric', 'min:0', 'max:100'],
            'savings_types' => ['required', 'array', 'min:1'],
            'savings_types.*' => [Rule::in(MemberContribution::SAVINGS_TYPES)],
            'savings_period' => ['required', Rule::in(['year', 'all_time'])],
            'sales_source' => ['required', Rule::in(['pos', 'excel'])],
            'rounding' => ['required', Rule::in(['round', 'ceil', 'floor', 'none'])],
            'excluded_customer_ids' => ['nullable', 'array'],
            'excluded_customer_ids.*' => ['integer', 'exists:customers,id'],
        ]);

        if (abs($data['portion_savings'] + $data['portion_purchases'] + $data['portion_loans'] - 100) > 0.001) {
            return back()->withErrors(['portion_savings' => 'Total porsi simpanan + pembelian + pinjaman harus 100%.']);
        }

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
            $a->loan_amount,
            round($a->loan_share * 100, 4),
            $a->base_amount,
            round($a->shu_savings),
            round($a->shu_purchases),
            round($a->shu_loans),
            round($a->total),
            $a->total_rounded,
        ])->all();

        return Excel::download(new ArrayExport(
            ['NIP', 'Nama', 'Jenis', 'Status', 'Simpanan', 'Porsi Simpanan (%)', 'Pembelian', 'Porsi Pembelian (%)', 'Angsuran Pinjaman', 'Porsi Pinjaman (%)', 'Base SHU', 'SHU Simpanan', 'SHU Pembelian', 'SHU Pinjaman', 'Total SHU', 'Total Dibulatkan'],
            $rows,
            ['A' => '@'],
        ), "shu-{$shu->year}.xlsx");
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
        $roundingText = [
            'round' => 'dibulatkan ke Rp 1.000 terdekat',
            'ceil' => 'dibulatkan ke atas ke kelipatan Rp 1.000',
            'floor' => 'dibulatkan ke bawah ke kelipatan Rp 1.000',
            'none' => 'tidak dibulatkan',
        ][$period->rounding] ?? '';

        return array_values(array_filter([
            "SHU ini untuk tahun buku {$period->year} dan dibagikan pada tahun ".($period->year + 1).'.',
            'Base SHU '.$rupiah($period->base_per_person)." per orang diberikan kepada setiap pegawai (selain PPNPN) yang membayar simpanan (pokok/wajib/sukarela) pada tahun {$period->year}.",
            "Pegawai yang mutasi atau pensiun pada tahun {$period->year} tetap berhak selama masih tercatat membayar simpanan di tahun itu. Yang berhenti membayar sebelum {$period->year} tidak berhak.",
            'Sisa SHU setelah base dibagi: '.$period->portion_savings.'% jasa simpanan, '.$period->portion_purchases.'% jasa pembelian'.($period->portion_loans > 0 ? ', '.$period->portion_loans.'% jasa pinjaman' : '').'.',
            "Jasa simpanan dihitung dari {$types} ".($period->savings_period === 'all_time' ? "yang terkumpul sejak awal s.d. {$period->year}" : "yang dibayar selama tahun {$period->year}").', proporsional terhadap total semua anggota yang membayar.',
            'Jasa pembelian dihitung dari total belanja '.($period->sales_source === 'excel' ? 'yang diimport dari Excel' : 'di POS (dikurangi retur)')." selama tahun {$period->year}, proporsional terhadap total belanja semua pegawai.",
            'Pegawai PPNPN/kontrak tidak mendapat base SHU, tetapi tetap mendapat jasa pembelian (dan jasa simpanan bila membayar simpanan).',
            $period->portion_loans > 0 ? "Jasa pinjaman dihitung dari angsuran pinjaman yang dibayar selama tahun {$period->year}." : null,
            count($period->excluded_customer_ids ?? []) > 0 ? count($period->excluded_customer_ids).' nama dikecualikan dan tidak mendapat SHU.' : null,
            'Pembeli umum/non-pegawai tidak mendapat SHU.',
            "Nilai SHU per orang {$roundingText}.",
        ]));
    }
}
