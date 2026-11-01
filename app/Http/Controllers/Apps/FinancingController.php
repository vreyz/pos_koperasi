<?php

namespace App\Http\Controllers\Apps;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Financing;
use App\Models\FinancingInstallment;
use App\Models\Setting;
use App\Services\Cooperative\FinancingImportService;
use App\Services\Cooperative\FinancingService;
use App\Services\Cooperative\FinancingSettings;
use App\Services\Cooperative\ImportFileStore;
use App\Services\Cooperative\NameMatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class FinancingController extends Controller
{
    public function __construct(
        private readonly FinancingService $service,
        private readonly FinancingImportService $importer,
        private readonly ImportFileStore $files,
    ) {}

    public function index(Request $request, string $type)
    {
        $financings = Financing::query()
            ->where('type', $type)
            ->with('customer:id,name,nip,employee_type,employment_status,active_until')
            ->withSum(['installments as outstanding' => fn ($q) => $q->whereNull('paid_at')], 'amount')
            ->withCount(['installments as paid_count' => fn ($q) => $q->whereNotNull('paid_at')])
            ->orderByDesc('disbursed_at')
            ->orderByDesc('id')
            ->get();

        $active = $financings->where('status', 'berjalan');

        return Inertia::render('Dashboard/Cooperative/Financing/Index', [
            'type' => $type,
            'typeLabel' => Financing::TYPE_LABELS[$type],
            'financings' => $financings,
            'statusLabels' => Financing::STATUS_LABELS,
            'summary' => [
                'active' => $active->count(),
                'outstanding' => (int) $active->sum('outstanding'),
                'bad_debt' => $financings->where('is_bad_debt', true)->where('status', 'berjalan')->count(),
                'waiting' => $financings->whereIn('status', ['pengajuan', 'diperiksa', 'direview', 'disetujui'])->count(),
                'income_year' => array_sum($this->service->incomeByCustomer($type, (int) now()->year)),
            ],
            'settings' => FinancingSettings::all(),
        ]);
    }

    /** Unduh daftar pembiayaan; file ini bisa diunggah ulang di Impor Excel (memperbarui, tidak dobel). */
    public function export(string $type)
    {
        $rows = Financing::query()->where('type', $type)
            ->with('customer:id,name,nip')
            ->withSum(['installments as outstanding' => fn ($q) => $q->whereNull('paid_at')], 'amount')
            ->withCount(['installments as paid_count' => fn ($q) => $q->whereNotNull('paid_at')])
            ->orderBy('disbursed_at')->orderBy('id')->get()
            ->map(fn (Financing $f) => [
                Financing::TYPE_LABELS[$f->type], $f->number, $f->customer?->nip, $f->customer?->name, $f->item_name,
                $f->applied_at?->format('d/m/Y'), $f->disbursed_at?->format('d/m/Y'),
                (int) $f->principal, (float) $f->margin_percent, (int) $f->admin_fee, (int) $f->tenor, (int) $f->installment,
                (int) $f->paid_count, (int) $f->outstanding, Financing::STATUS_LABELS[$f->status] ?? $f->status,
                $f->is_bad_debt ? 'Ya' : '', $f->bank_name, $f->bank_account, $f->notes,
            ])->all();

        return Excel::download(new ArrayExport(FinancingImportService::APP_HEADERS, $rows, ['B' => '@', 'C' => '@', 'R' => '@']), "pembiayaan-{$type}.xlsx");
    }

    public function create(Request $request, string $type)
    {
        return Inertia::render('Dashboard/Cooperative/Financing/Form', [
            'type' => $type,
            'typeLabel' => Financing::TYPE_LABELS[$type],
            'financing' => null,
            'employees' => $this->employees(),
            'settings' => FinancingSettings::all(),
            'preselect' => $request->integer('customer_id') ?: null,
        ]);
    }

    public function store(Request $request, string $type)
    {
        [$data, $customer] = $this->validated($request, $type);
        $financing = Financing::create($data + ['type' => $type, 'status' => 'pengajuan', 'created_by' => $request->user()->id]);

        return to_route('financing.show', $financing)->with('success', 'Pengajuan pembiayaan dibuat. Lanjutkan pemeriksaan, review, lalu persetujuan ketua.');
    }

    public function show(Financing $financing)
    {
        $financing->load(['customer', 'installments', 'checker:id,name', 'reviewer:id,name', 'approver:id,name', 'rejecter:id,name']);
        $customer = $financing->customer;

        return Inertia::render('Dashboard/Cooperative/Financing/Show', [
            'financing' => $financing,
            'typeLabel' => Financing::TYPE_LABELS[$financing->type],
            'statusLabels' => Financing::STATUS_LABELS,
            'steps' => FinancingService::STEPS,
            'outstanding' => $this->service->outstanding($financing),
            'otherActive' => Financing::where('customer_id', $customer->id)->whereKeyNot($financing->id)->where('status', 'berjalan')
                ->withSum(['installments as outstanding' => fn ($q) => $q->whereNull('paid_at')], 'amount')
                ->get(['id', 'type', 'principal', 'disbursed_at']),
            'mutationWarning' => $customer->active_until && $financing->status === 'berjalan',
        ]);
    }

    public function edit(Financing $financing)
    {
        abort_unless(in_array($financing->status, ['pengajuan', 'diperiksa', 'direview', 'disetujui'], true), 403, 'Pembiayaan yang sudah dicairkan tidak bisa diubah.');

        return Inertia::render('Dashboard/Cooperative/Financing/Form', [
            'type' => $financing->type,
            'typeLabel' => Financing::TYPE_LABELS[$financing->type],
            'financing' => $financing,
            'employees' => $this->employees(),
            'settings' => FinancingSettings::all(),
            'preselect' => null,
        ]);
    }

    public function update(Request $request, Financing $financing)
    {
        abort_unless(in_array($financing->status, ['pengajuan', 'diperiksa', 'direview', 'disetujui'], true), 403);
        [$data] = $this->validated($request, $financing->type);
        $financing->update($data);

        return to_route('financing.show', $financing)->with('success', 'Data pembiayaan diperbarui.');
    }

    public function destroy(Financing $financing)
    {
        abort_unless(in_array($financing->status, ['pengajuan', 'ditolak'], true), 403, 'Hanya pengajuan atau yang ditolak yang bisa dihapus.');
        $type = $financing->type;
        $financing->delete();

        return to_route('financing.index', $type)->with('success', 'Pengajuan dihapus.');
    }

    public function advance(Request $request, Financing $financing, string $step)
    {
        $rule = FinancingService::STEPS[$step] ?? abort(404);
        abort_unless($request->user()->can($rule['permission']), 403);

        return $this->attempt(fn () => $this->service->advance($financing, $step, $request->user()->id), $rule['label'].' — berhasil.');
    }

    public function reject(Request $request, Financing $financing)
    {
        abort_unless($request->user()->canAny(['financing-check', 'financing-review', 'financing-approve']), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->service->reject($financing, $data['reason'], $request->user()->id), 'Pengajuan ditolak.');
    }

    public function disburse(Request $request, Financing $financing)
    {
        $data = $request->validate([
            'disbursed_at' => ['required', 'date'],
            'transferred_amount' => ['nullable', 'integer', 'min:0'],
            'first_due_month' => ['nullable', 'date'],
        ]);

        return $this->attempt(
            fn () => $this->service->disburse($financing, $data['disbursed_at'], $data['transferred_amount'] ?? null, $data['first_due_month'] ?? null),
            'Dana dicairkan, jadwal cicilan dibuat.'
        );
    }

    public function toggleInstallment(Request $request, FinancingInstallment $installment)
    {
        $paid = ! $installment->paid_at;
        $this->service->setPaid($installment, $paid, 'manual', $paid ? ($request->input('paid_at') ?: now()->toDateString()) : null);

        return back()->with('success', 'Cicilan ke-'.$installment->seq.($paid ? ' ditandai lunas.' : ' dibatalkan.'));
    }

    public function payOff(Request $request, Financing $financing)
    {
        abort_unless($financing->status === 'berjalan', 422);
        $count = $this->service->payOff($financing, $request->input('note') ?: 'Pelunasan dipercepat');

        return back()->with('success', "Pelunasan dipercepat: {$count} cicilan ditandai lunas.");
    }

    public function toggleBadDebt(Financing $financing)
    {
        $financing->update(['is_bad_debt' => ! $financing->is_bad_debt]);

        return back()->with('success', $financing->is_bad_debt ? 'Ditandai macet.' : 'Tanda macet dihapus.');
    }

    public function print(Financing $financing, string $doc)
    {
        abort_unless(in_array($doc, ['formulir', 'pencairan'], true), 404);

        return Inertia::render('Dashboard/Cooperative/Financing/Print', [
            'doc' => $doc,
            'type' => $financing->type,
            'financing' => $financing->load(['customer', 'checker:id,name', 'reviewer:id,name', 'approver:id,name']),
            'settings' => FinancingSettings::all(),
            'coop' => FinancingSettings::coop(),
            'ppnpn' => $financing->customer->employee_type === 'PPNPN',
        ]);
    }

    public function blankForm(Request $request, string $type)
    {
        return Inertia::render('Dashboard/Cooperative/Financing/Print', [
            'doc' => 'formulir',
            'type' => $type,
            'financing' => null,
            'settings' => FinancingSettings::all(),
            'coop' => FinancingSettings::coop(),
            'ppnpn' => $request->boolean('ppnpn'),
        ]);
    }

    public function reconcile(Request $request)
    {
        $year = (int) $request->query('year', now()->year);

        return Inertia::render('Dashboard/Cooperative/Financing/Reconcile', [
            'year' => $year,
            'rows' => $this->service->reconcile($year),
        ]);
    }

    public function markFromPayroll(Request $request)
    {
        $year = (int) $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']])['year'];
        $count = $this->service->markPaidFromPayroll($year);

        return back()->with('success', "{$count} cicilan ditandai lunas sesuai potongan gaji {$year}.");
    }

    public function updateSettings(Request $request, string $type)
    {
        $rules = [];
        foreach (array_intersect_key(FinancingSettings::DEFAULTS, array_flip(FinancingSettings::KEYS[$type])) as $key => $default) {
            $rules[$key] = is_numeric($default) ? ['required', 'numeric', 'min:0'] : ['nullable', 'string', 'max:255'];
        }
        $data = $request->validate($rules);
        foreach ($data as $key => $value) {
            Setting::set($key, $value ?? '');
        }

        return back()->with('success', 'Aturan '.Financing::TYPE_LABELS[$type].' disimpan.');
    }

    public function import(Request $request)
    {
        set_time_limit(600); // file Excel besar (mis. rekap potongan 2020–2026)
        $token = $request->query('token');
        $path = $this->files->path('financing', $token);
        if ($token && ! $path) {
            return to_route('financing.import')->with('error', 'File pratinjau sudah kedaluwarsa. Upload ulang.');
        }
        $preview = null;
        if ($path) {
            try {
                $preview = $this->importer->preview($this->importer->parse($path)) + ['token' => $token];
            } catch (RuntimeException $e) {
                $this->files->forget('financing', $token);

                return to_route('financing.import')->with('error', $e->getMessage());
            }
        }

        return Inertia::render('Dashboard/Cooperative/Financing/Import', [
            'preview' => $preview,
            'employees' => $path ? $this->employees() : [],
        ]);
    }

    public function previewImport(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:20480']]);

        return to_route('financing.import', ['token' => $this->files->put($request->file('file'), 'financing')]);
    }

    public function commitImport(Request $request)
    {
        set_time_limit(600); // file Excel besar (mis. rekap potongan 2020–2026)
        $data = $request->validate(['token' => ['required', 'uuid'], 'mapping' => ['array']]);
        $path = $this->files->path('financing', $data['token']);
        if (! $path) {
            return to_route('financing.import')->with('error', 'File pratinjau sudah kedaluwarsa. Upload ulang.');
        }
        $parsed = $this->importer->parse($path);
        $mapping = $this->resolveNewEmployees($parsed, $data['mapping'] ?? []);
        $summary = $this->importer->commit($parsed, $mapping, $request->user()->id);
        $this->files->forget('financing', $data['token']);

        $message = "Impor pembiayaan: {$summary['created']} baru, {$summary['updated']} diperbarui, {$summary['skipped']} dilewati, {$summary['fees_applied']} biaya formulir dari daftar pencairan.";
        if ($summary['fees_unmatched'] !== []) {
            $message .= ' Pencairan tanpa kontrak yang cocok: '.implode(', ', $summary['fees_unmatched']).'.';
        }

        return to_route('financing.index', 'dana')->with('success', $message);
    }

    /**
     * Pilihan "buat pegawai baru" di layar pencocokan: buat dulu, lalu pakai id-nya.
     */
    private function resolveNewEmployees(array $parsed, array $mapping): array
    {
        $hints = [];
        foreach ($parsed['contracts'] as $row) {
            $hints[NameMatcher::normalize($row['name'])] ??= [$row['name'], $row['status_hint'] ?? null];
        }
        foreach ($mapping as $key => $value) {
            if ($value === 'new' && isset($hints[$key])) {
                [$name, $hint] = $hints[$key];
                $mapping[$key] = Customer::create([
                    'name' => $name,
                    'employee_type' => $hint === 'PPNPN' ? 'PPNPN' : 'LAINNYA',
                    'employment_status' => 'AKTIF',
                    'no_telp' => 0,
                    'address' => '',
                ])->id;
            } elseif (! is_numeric($value)) {
                $mapping[$key] = null;
            }
        }

        return $mapping;
    }

    private function validated(Request $request, string $type): array
    {
        $data = $request->validate([
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNotNull('employee_type')],
            'number' => ['nullable', 'string', 'max:100'],
            'applied_at' => ['nullable', 'date'],
            'item_name' => [$type === 'barang' ? 'required' : 'nullable', 'string', 'max:255'],
            'principal' => ['required', 'integer', 'min:1'],
            'tenor' => ['required', 'integer', 'min:1', 'max:36'],
            'margin_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'admin_fee' => ['nullable', 'integer', 'min:0'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'family_name' => ['nullable', 'string', 'max:255'],
            'family_relation' => ['nullable', 'string', 'max:100'],
            'family_phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $customer = Customer::findOrFail($data['customer_id']);
        $data = $this->service->compute($data + ['type' => $type], $customer);
        unset($data['type']);

        $errors = $this->service->validateRules($data + ['type' => $type], $customer);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$data, $customer];
    }

    private function employees()
    {
        return Customer::employees()->orderBy('name')->get(['id', 'name', 'nip', 'employee_type', 'position', 'work_unit', 'employment_status']);
    }

    private function attempt(callable $action, string $success)
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
