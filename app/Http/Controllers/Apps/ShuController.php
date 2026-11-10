<?php

namespace App\Http\Controllers\Apps;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\ExternalSale;
use App\Models\MemberContribution;
use App\Models\ShuPeriod;
use App\Models\Transaction;
use App\Services\Cooperative\BoardService;
use App\Services\Cooperative\ExternalSalesImportService;
use App\Services\Cooperative\ImportFileStore;
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
        private readonly ImportFileStore $files,
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
        $fromDecrees = array_map(fn ($m) => ['customer_id' => $m['customer_id'], 'months' => $m['months']], app(BoardService::class)->membersForYear($data['year']));
        $period = ShuPeriod::create([
            'year' => $data['year'],
            'total_shu' => 0,
            'base_per_person' => $previous->base_per_person ?? 100000,
            'base_requires_savings' => $previous->base_requires_savings ?? true,
            'base_for_ppnpn' => $previous->base_for_ppnpn ?? true,
            'base_mode' => $previous->base_mode ?? 'full',
            'board_amount' => $previous->board_amount ?? 0,
            'board_members' => $fromDecrees ?: ($previous->board_members ?? []),
            'rounding_unit' => $previous->rounding_unit ?? 1000,
            // Bawaan: simpanan 40%, jasa usaha 60% (belanja 40 + pendapatan pembiayaan 20).
            'portion_savings' => $previous->portion_savings ?? 40,
            'portion_purchases' => $previous->portion_purchases ?? 40,
            'portion_loans' => 0,
            'portion_financing' => $previous->portion_financing ?? 20,
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
            'boardFromDecrees' => app(BoardService::class)->membersForYear($shu->year),
            // Bulan membayar Iuran Koperasi per pegawai: bulan pengurus tanpa iuran tidak dihitung.
            'paidMonths' => (object) $this->calculator->paidMonths($shu->year),
            'nonEmployeeBuyers' => $this->nonEmployeeBuyers($shu, $unmatched),
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
            'board_members.*.months' => ['required', 'array', 'min:1'],
            'board_members.*.months.*' => ['integer', 'between:1,12'],
            'portion_savings' => ['required', 'numeric', 'min:0', 'max:100'],
            'portion_purchases' => ['required', 'numeric', 'min:0', 'max:100'],
            'portion_financing' => ['required', 'numeric', 'min:0', 'max:100'],
            'savings_types' => ['required', 'array', 'min:1'],
            'savings_types.*' => [Rule::in(MemberContribution::SAVINGS_TYPES)],
            'savings_period' => ['required', Rule::in(['year', 'all_time'])],
            'sales_source' => ['required', Rule::in(['pos', 'excel'])],
            'rounding' => ['required', Rule::in(['round', 'ceil', 'floor', 'none'])],
            'rounding_unit' => ['required', 'integer', Rule::in([1, 100, 500, 1000, 5000, 10000])],
            'excluded_customer_ids' => ['nullable', 'array'],
            'excluded_customer_ids.*' => ['integer', 'exists:customers,id'],
        ]);

        if (abs($data['portion_savings'] + $data['portion_purchases'] + $data['portion_financing'] - 100) > 0.001) {
            return back()->withErrors(['portion_savings' => 'Total porsi simpanan + belanja + pendapatan pembiayaan harus 100%.']);
        }
        $data['portion_loans'] = 0;
        $data['portion_financing_dana'] = 0;
        $data['portion_financing_barang'] = 0;
        $data['board_amount'] = (int) ($data['board_amount'] ?? 0);
        $data['board_members'] = array_values(array_map(fn ($m) => [
            'customer_id' => (int) $m['customer_id'],
            'months' => ShuCalculator::boardMonths($m),
        ], $data['board_members'] ?? []));

        $data['excluded_customer_ids'] = array_values(array_unique(array_map('intval', $data['excluded_customer_ids'] ?? [])));
        $shu->fill($data);
        if ($capErrors = $this->calculator->financingCapErrors($shu)) {
            return back()->withErrors($capErrors);
        }
        $shu->save();

        return $this->recalculate($shu, 'Pengaturan SHU disimpan');
    }

    /**
     * Hitung ulang SHU setelah perubahan (pengaturan, koreksi, pencocokan/impor penjualan) — tanpa tombol "Hitung SHU".
     * SHU final tidak dihitung ulang.
     */
    private function recalculate(ShuPeriod $shu, string $done)
    {
        if ($shu->isFinal()) {
            return back()->with('success', "{$done}.");
        }
        try {
            $result = $this->calculator->calculate($shu->fresh());
        } catch (RuntimeException $e) {
            return back()->with('error', "{$done}, tetapi SHU belum bisa dihitung: ".$e->getMessage());
        }

        return back()->with('success', "{$done} dan SHU {$shu->year} dihitung ulang: {$result['recipients']} penerima ({$result['base_recipients']} dapat SHU dasar).");
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

            return $this->recalculate($shu, 'Koreksi dihapus');
        }

        $shu->adjustments()->updateOrCreate(['customer_id' => $data['customer_id']], $data);

        return $this->recalculate($shu, 'Koreksi disimpan');
    }

    public function finalize(ShuPeriod $shu)
    {
        if (! $shu->calculated_at) {
            return back()->with('error', 'SHU belum dihitung. Klik "Simpan dan Kalkulasi Ulang" dulu sebelum dikunci.');
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

    /** Halaman impor penjualan: upload → hasil validasi + cocokkan nama → simpan. */
    public function salesImportPage(Request $request, ShuPeriod $shu)
    {
        set_time_limit(600);
        $token = $request->query('token');
        $path = $this->files->path('sales', $token);
        if ($token && ! $path) {
            return to_route('shu.sales-import.page', $shu)->with('error', 'File pratinjau sudah kedaluwarsa. Upload ulang.');
        }

        $preview = null;
        if ($path) {
            try {
                $data = $this->salesImport->inspect($path, $shu->year);
                $preview = $this->salesImport->preview($data);
                $preview += [
                    'token' => $token,
                    'file_name' => (string) $request->session()->get("sales_import_name.{$token}", ''),
                    'sheet' => $data['sheet'],
                    'checks' => $this->salesImport->validate($data, $this->salesImport->autoMapping($preview), $shu),
                ];
                // Layar hanya perlu nama yang belum cocok dengan pegawai (yang cocok cukup dihitung).
                $preview['names'] = array_values(array_filter($preview['names'], fn ($n) => ! $n['employee']));
            } catch (RuntimeException $e) {
                $this->files->forget('sales', $token);

                return to_route('shu.sales-import.page', $shu)->with('error', $e->getMessage());
            }
        }

        return Inertia::render('Dashboard/Cooperative/Shu/SalesImport', [
            'period' => $shu->only(['id', 'year', 'status', 'sales_source']),
            'current' => ['rows' => ExternalSale::where('year', $shu->year)->count(), 'total' => (int) ExternalSale::where('year', $shu->year)->sum('amount')],
            'preview' => $preview,
            'employees' => $path ? Customer::employees()->orderBy('name')->get(['id', 'name', 'nip', 'employee_type', 'employment_status']) : [],
        ]);
    }

    public function previewSales(Request $request, ShuPeriod $shu)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480']]);
        $token = $this->files->put($request->file('file'), 'sales');
        $request->session()->put("sales_import_name.{$token}", $request->file('file')->getClientOriginalName());

        return to_route('shu.sales-import.page', [$shu, 'token' => $token]);
    }

    public function validateSales(Request $request, ShuPeriod $shu)
    {
        set_time_limit(600);
        $validated = $request->validate(['token' => ['required', 'uuid'], 'mapping' => ['array']]);
        $path = $this->files->path('sales', $validated['token']);
        if (! $path) {
            return response()->json(['message' => 'File pratinjau sudah kedaluwarsa. Upload ulang.'], 410);
        }
        $data = $this->salesImport->inspect($path, $shu->year);

        return response()->json(['checks' => $this->salesImport->validate($data, $this->salesMapping($data, $validated['mapping'] ?? []), $shu)]);
    }

    public function commitSales(Request $request, ShuPeriod $shu)
    {
        set_time_limit(600);
        $validated = $request->validate(['token' => ['required', 'uuid'], 'mapping' => ['array']]);
        $path = $this->files->path('sales', $validated['token']);
        if (! $path) {
            return to_route('shu.sales-import.page', $shu)->with('error', 'File pratinjau sudah kedaluwarsa. Upload ulang.');
        }
        $data = $this->salesImport->inspect($path, $shu->year);
        $mapping = $this->salesMapping($data, $validated['mapping'] ?? []);
        $checks = $this->salesImport->validate($data, $mapping, $shu);
        if (ExternalSalesImportService::hasErrors($checks)) {
            return to_route('shu.sales-import.page', [$shu, 'token' => $validated['token']])
                ->with('error', 'Belum disimpan — validasi menemukan kesalahan: '.collect($checks)->firstWhere('level', 'error')['title']);
        }

        $result = $this->salesImport->commit($data, $mapping);
        $this->files->forget('sales', $validated['token']);
        $request->session()->forget("sales_import_name.{$validated['token']}");

        $message = 'Penjualan Excel diimport: '.number_format($result['rows'], 0, ',', '.').' baris, total Rp '.number_format($result['total'], 0, ',', '.')." ({$result['unmatched_names']} nama bukan pegawai/belum cocok)";
        if ($shu->sales_source === 'excel' && ! $shu->isFinal()) {
            try {
                $calc = $this->calculator->calculate($shu->fresh());
                $message .= " — SHU {$shu->year} dihitung ulang: {$calc['recipients']} penerima.";
            } catch (RuntimeException $e) {
                return to_route('shu.show', $shu)->with('error', $message.', tetapi SHU belum bisa dihitung: '.$e->getMessage());
            }
        }

        return to_route('shu.show', $shu)->with('success', $message);
    }

    /**
     * Pemetaan otomatis + pilihan di layar (hanya id pegawai yang sah; kosong = tetap otomatis).
     *
     * @return array<string, int|null>
     */
    private function salesMapping(array $data, array $raw): array
    {
        $mapping = $this->salesImport->autoMapping($this->salesImport->preview($data));
        $chosen = collect($raw)->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v);
        $valid = Customer::employees()->whereIn('id', $chosen->values())->pluck('id')->flip();
        foreach ($chosen as $key => $id) {
            if (isset($valid[$id]) && array_key_exists($key, $mapping)) {
                $mapping[$key] = $id;
            }
        }

        return $mapping;
    }

    public function mapSalesName(Request $request, ShuPeriod $shu)
    {
        $data = $request->validate([
            'buyer_name' => ['required', 'string'],
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNotNull('employee_type')],
        ]);
        $count = $this->salesImport->mapName($shu->year, $data['buyer_name'], (int) $data['customer_id']);

        return $this->recalculate($shu, "\"{$data['buyer_name']}\" dipetakan ({$count} baris), otomatis cocok di impor berikutnya,");
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
            $a->financing_barang_amount,
            $a->financing_amount,
            round($a->financing_share * 100, 4),
            $a->months_paid,
            $a->base_amount,
            $a->board_amount,
            round($a->shu_savings),
            round($a->shu_purchases),
            round($a->shu_financing),
            $a->adjustment_amount,
            $a->adjustment_note,
            round($a->total),
            $a->total_rounded,
        ])->all();

        return Excel::download(new ArrayExport(
            ['NIP', 'Nama', 'Jenis', 'Status', 'Simpanan Koperasi', 'Porsi Simpanan (%)', 'Belanja Toko', 'Porsi Belanja (%)', 'Pembiayaan Dana (biaya formulir)', 'Pembiayaan Barang (bagi hasil + biaya lain)', 'Pendapatan Pembiayaan', 'Porsi Pendapatan Pembiayaan (%)', 'Bulan Bayar', 'SHU Dasar', 'SHU Pengurus Koperasi', 'SHU Simpanan', 'SHU Belanja', 'SHU Pendapatan Pembiayaan', 'Koreksi', 'Catatan Koreksi', 'Total SHU', 'Total Dibulatkan'],
            $rows,
            ['A' => '@'],
        ), "shu-{$shu->year}.xlsx");
    }

    /**
     * @param  array<int, array{customer_id: int, amount: int}>  $rows
     */
    /**
     * Pembeli di tahun buku yang bukan pegawai (pembeli umum, atau nama belum cocok): tidak ikut SHU.
     *
     * @return list<array{name: string, amount: int}>
     */
    private function nonEmployeeBuyers(ShuPeriod $shu, $unmatched): array
    {
        if ($shu->sales_source === 'excel') {
            return $unmatched->map(fn ($r) => ['name' => $r->buyer_name, 'amount' => (int) $r->total])->values()->all();
        }

        return Transaction::query()
            ->leftJoin('customers', 'customers.id', '=', 'transactions.customer_id')
            ->whereNull('customers.employee_type')
            ->whereIn('transactions.payment_status', ShuCalculator::PURCHASE_STATUSES)
            ->where('transactions.created_at', '>=', sprintf('%04d-01-01 00:00:00', $shu->year))
            ->where('transactions.created_at', '<', sprintf('%04d-01-01 00:00:00', $shu->year + 1))
            ->selectRaw("COALESCE(customers.name, 'Umum (tanpa pelanggan)') as name, SUM(transactions.grand_total) as amount")
            ->groupBy('name')->orderByDesc('amount')->get()
            ->map(fn ($r) => ['name' => $r->name, 'amount' => (int) $r->amount])->all();
    }

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

        $boardOut = array_flip($this->calculator->boardWithoutSavings($period));
        $boardEligible = array_filter($period->board_members ?? [], fn ($m) => ! isset($boardOut[(int) ($m['customer_id'] ?? 0)]));
        $boardCount = count($boardEligible);
        $paid = $this->calculator->paidMonths($y, array_map(fn ($m) => (int) ($m['customer_id'] ?? 0), $boardEligible));
        $boardMonths = array_sum(array_map(fn ($m) => count(array_intersect(ShuCalculator::boardMonths($m), $paid[(int) ($m['customer_id'] ?? 0)] ?? [])), $boardEligible));

        return array_values(array_filter([
            "SHU ini untuk tahun buku {$y} dan dibagikan pada tahun ".($y + 1).'.',
            "Yang ikut hanya pegawai yang aktif di tahun {$y} (masa aktif di Data Pegawai mencakup {$y}). Non-pegawai tidak mendapat SHU walaupun berbelanja.",
            "Penerima harus punya Simpanan Koperasi, Belanja Toko, atau Pembiayaan Dana/Barang di tahun {$y}. Pegawai mutasi/pensiun di tahun {$y} tetap berhak.",
            "Penarikan simpanan di tahun {$y} (mis. simpanan diambil saat mutasi) tidak mengurangi hak SHU tahun {$y}: yang dihitung adalah setoran simpanan.",
            'SHU dasar '.$rupiah($period->base_per_person).($period->base_mode === 'monthly' ? ' × (jumlah bulan membayar simpanan ÷ 12)' : ' per orang')
                .($period->base_requires_savings ? " untuk anggota yang membayar Simpanan Koperasi (pokok/wajib/sukarela) di tahun {$y}." : ' untuk semua pegawai aktif yang ikut (simpanan, belanja, atau pembiayaan).')
                .($period->base_for_ppnpn ? ' PPNPN juga mendapat SHU dasar.' : ' PPNPN tidak mendapat SHU dasar.'),
            $period->base_requires_savings ? 'Pegawai yang berbelanja tetapi tidak membayar simpanan tetap mendapat bagian Belanja Toko, tanpa SHU dasar.' : null,
            $boardCount > 0 && $period->board_amount > 0 ? "SHU Pengurus Koperasi {$rupiah($period->board_amount)} per orang per bulan bertugas yang dibayar simpanannya; bulan tanpa simpanan (pindah/pensiun) tidak dihitung ({$boardCount} pengurus, total {$boardMonths} orang-bulan = {$rupiah($period->board_amount * $boardMonths)}), dipotong dari SHU lebih dulu." : null,
            $boardOut !== [] ? count($boardOut)." pengurus tidak membayar Simpanan Koperasi di tahun {$y}, sehingga tidak mendapat SHU Pengurus Koperasi." : null,
            $adjustments > 0 ? "{$adjustments} koreksi manual (SHU dasar pengganti / tambahan nominal) diterapkan dan dipotong dari SHU lebih dulu." : null,
            'Sisa SHU (setelah SHU dasar, SHU pengurus koperasi, dan koreksi) dibagi: '.$period->portion_savings.'% Simpanan Koperasi, '.$period->portion_purchases.'% Belanja Toko, '.$period->portion_financing.'% Pendapatan Pembiayaan (total 100%).',
            "Bagian Simpanan Koperasi dibagi sebanding jumlah {$types} ".($period->savings_period === 'all_time' ? "yang terkumpul sejak awal s.d. {$y}" : "yang dibayar selama tahun {$y}").' dibanding total semua anggota.',
            'Bagian Belanja Toko dibagi sebanding total belanja '.($period->sales_source === 'excel' ? '(impor Excel)' : 'di POS (dikurangi retur)')." selama tahun {$y} dibanding total belanja semua pegawai aktif.",
            "Bagian Pendapatan Pembiayaan dibagi sebanding pendapatan koperasi dari pembiayaan tiap pegawai di tahun {$y}: biaya formulir pembiayaan dana yang dicairkan + bagi hasil pembiayaan barang (dari cicilan yang dibayar) + biaya lain saat pencairan. Cicilan/angsuran pokoknya tidak dihitung.",
            'Bagian Pendapatan Pembiayaan paling banyak '.(\App\Services\Cooperative\ShuCalculator::FINANCING_SHU_MAX_SHARE * 100).'% dari total pendapatan pembiayaan koperasi di tahun '.$y.'.',
            count($period->excluded_customer_ids ?? []) > 0 ? count($period->excluded_customer_ids).' nama dikecualikan dan tidak mendapat SHU.' : null,
            "Nilai SHU per orang {$roundingText}.",
        ]));
    }
}
