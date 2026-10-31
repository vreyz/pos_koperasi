<?php

namespace App\Http\Controllers\Apps;

use App\Exports\ArrayExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAlias;
use App\Models\MemberContribution;
use App\Services\Cooperative\EmployeeImportService;
use App\Services\Cooperative\ImportFileStore;
use App\Services\Cooperative\NameMatcher;
use App\Services\Cooperative\Nip;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeImportService $importService,
        private readonly ImportFileStore $files,
    ) {}

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $type = (string) $request->query('type', '');
        $status = (string) $request->query('status', '');

        $employees = Customer::employees()
            ->with('aliases:id,customer_id,alias')
            ->withSum(['contributions as savings_total' => fn ($q) => $q->whereIn('type', MemberContribution::SAVINGS_TYPES)], 'amount')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('nip', 'like', "%{$search}%")
                ->orWhereHas('aliases', fn ($a) => $a->where('alias', 'like', '%'.NameMatcher::normalize($search).'%'))))
            ->when($type !== '', fn ($q) => $q->where('employee_type', $type))
            ->when($status !== '', fn ($q) => $q->where('employment_status', $status))
            ->orderBy('name')
            ->paginate($this->perPage(25))
            ->withQueryString();

        $employees->getCollection()->transform(function (Customer $c) {
            $c->setAttribute('nip_problem', $c->nip ? Nip::problem($c->nip) : null);

            return $c;
        });

        return Inertia::render('Dashboard/Cooperative/Employees/Index', [
            'employees' => $employees,
            'filters' => ['search' => $search, 'type' => $type, 'status' => $status],
            'types' => Customer::EMPLOYEE_TYPES,
            'statuses' => Customer::employees()->whereNotNull('employment_status')->distinct()->orderBy('employment_status')->pluck('employment_status'),
            'summary' => [
                'total' => Customer::employees()->count(),
                'by_type' => Customer::employees()->selectRaw('employee_type, COUNT(*) as total')->groupBy('employee_type')->pluck('total', 'employee_type'),
                'without_nip' => Customer::employees()->whereNull('nip')->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $customer = Customer::create(collect($data)->except('aliases')->all() + ['no_telp' => 0, 'address' => '']);
        $this->syncAliases($customer, $data['aliases'] ?? '');

        return back()->with('success', 'Pegawai ditambahkan.');
    }

    public function update(Request $request, Customer $employee)
    {
        $data = $this->validated($request, $employee);
        $employee->update(collect($data)->except('aliases')->all());
        $this->syncAliases($employee, $data['aliases'] ?? '');

        return back()->with('success', 'Data pegawai diperbarui.');
    }

    public function destroy(Customer $employee)
    {
        if ($employee->contributions()->exists() || $employee->transactions()->exists()) {
            return back()->with('error', 'Pegawai punya riwayat simpanan/transaksi. Ubah statusnya saja (mis. MUTASI/PENSIUN).');
        }
        $employee->delete();

        return back()->with('success', 'Pegawai dihapus.');
    }

    public function import(Request $request)
    {
        $token = $request->query('token');
        $preview = null;
        $path = $this->files->path('employees', $token);

        if ($token && ! $path) {
            return to_route('employees.import')->with('error', 'File pratinjau sudah kedaluwarsa. Upload ulang.');
        }

        if ($path) {
            try {
                $rows = array_values($this->importService->classify($this->importService->parse($path)));
            } catch (RuntimeException $e) {
                $this->files->forget('employees', $token);

                return to_route('employees.import')->with('error', $e->getMessage());
            }
            $counts = array_count_values(array_column($rows, 'status'));
            $preview = ['token' => $token, 'rows' => $rows, 'counts' => $counts];
        }

        return Inertia::render('Dashboard/Cooperative/Employees/Import', [
            'preview' => $preview,
            'fieldLabels' => EmployeeImportService::FIELD_LABELS,
        ]);
    }

    public function previewImport(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240']]);
        $token = $this->files->put($request->file('file'), 'employees');

        return to_route('employees.import', ['token' => $token]);
    }

    public function commitImport(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'uuid'],
            'actions' => ['array'],
            'actions.*' => ['string', 'regex:/^(create|update|skip|update:\d+)$/'],
        ]);
        $path = $this->files->path('employees', $validated['token']);
        if (! $path) {
            return to_route('employees.import')->with('error', 'File pratinjau sudah kedaluwarsa. Upload ulang.');
        }

        $classified = $this->importService->classify($this->importService->parse($path));
        $summary = $this->importService->commit($classified, $validated['actions'] ?? []);
        $this->files->forget('employees', $validated['token']);

        return to_route('employees.index')->with('success', "Import pegawai selesai: {$summary['created']} baru, {$summary['updated']} diperbarui, {$summary['skipped']} dilewati.");
    }

    public function template()
    {
        return Excel::download(new ArrayExport(
            ['NIP', 'Nama', 'Jenis', 'Pangkat', 'Gol', 'Jabatan', 'Unit', 'Status'],
            [
                ['199001012015031001', 'Contoh Pegawai PNS', 'PNS', 'Penata Muda', 'III.a', 'Pelaksana', 'BAGIAN UMUM', 'AKTIF'],
                ['', 'Contoh Pegawai Kontrak', 'PPNPN', '', '', 'Pramubakti', 'BAGIAN UMUM', 'AKTIF'],
            ],
            ['A' => '@'],
        ), 'template-pegawai.xlsx');
    }

    public function export()
    {
        $rows = Customer::employees()->orderBy('name')->get()->map(fn (Customer $c) => [
            $c->nip, $c->name, $c->employee_type, $c->rank, $c->grade, $c->position, $c->work_unit, $c->employment_status,
        ])->all();

        return Excel::download(new ArrayExport(['NIP', 'Nama', 'Jenis', 'Pangkat', 'Gol', 'Jabatan', 'Unit', 'Status'], $rows, ['A' => '@']), 'pegawai.xlsx');
    }

    private function validated(Request $request, ?Customer $employee = null): array
    {
        $request->merge(['nip' => Nip::normalize($request->input('nip')) ?: null]);

        $data = $request->validate([
            'nip' => ['nullable', 'string', 'max:30', Rule::unique('customers', 'nip')->ignore($employee?->id)],
            'name' => ['required', 'string', 'max:255'],
            'employee_type' => ['required', Rule::in(Customer::EMPLOYEE_TYPES)],
            'rank' => ['nullable', 'string', 'max:255'],
            'grade' => ['nullable', 'string', 'max:20'],
            'position' => ['nullable', 'string', 'max:255'],
            'work_unit' => ['nullable', 'string', 'max:255'],
            'employment_status' => ['nullable', 'string', 'max:30'],
            'aliases' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['employment_status'] = isset($data['employment_status']) ? strtoupper(trim($data['employment_status'])) : 'AKTIF';

        return $data;
    }

    private function syncAliases(Customer $customer, string $aliases): void
    {
        $keys = collect(preg_split('/[,;\n]/', $aliases))
            ->map(fn ($a) => NameMatcher::normalize($a))
            ->filter()
            ->reject(fn ($a) => $a === NameMatcher::normalize($customer->name))
            ->unique();

        $customer->aliases()->whereNotIn('alias', $keys)->delete();
        foreach ($keys as $key) {
            CustomerAlias::updateOrCreate(['alias' => $key], ['customer_id' => $customer->id]);
        }
    }
}
