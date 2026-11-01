<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\MemberContribution;
use App\Services\Cooperative\DeductionImportService;
use App\Services\Cooperative\ImportFileStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

/** Menu Angsuran & Potongan Koperasi: rekap potongan gaji per pegawai + impor bertahap. */
class DeductionController extends Controller
{
    public function __construct(private readonly DeductionImportService $importer, private readonly ImportFileStore $files) {}

    public function index(Request $request)
    {
        $years = MemberContribution::whereIn('type', MemberContribution::DEDUCTION_TYPES)->distinct()->orderByDesc('period_year')->pluck('period_year')->map(fn ($y) => (int) $y)->all();
        $year = (int) $request->query('year', $years[0] ?? now()->year);
        $month = (int) $request->query('month', 0);

        $sums = MemberContribution::where('period_year', $year)
            ->whereIn('type', MemberContribution::DEDUCTION_TYPES)
            ->when($month >= 1 && $month <= 12, fn ($q) => $q->where('period_month', $month))
            ->selectRaw('customer_id, type, SUM(amount) as total')
            ->groupBy('customer_id', 'type')->get()->groupBy('customer_id');
        $people = Customer::whereIn('id', $sums->keys())->get(['id', 'name', 'nip', 'employee_type', 'employment_status'])->keyBy('id');

        $rows = $sums->map(function ($group, $customerId) use ($people) {
            $person = $people[$customerId] ?? null;
            $row = ['customer_id' => (int) $customerId, 'name' => $person?->name, 'nip' => $person?->nip,
                'employee_type' => $person?->employee_type, 'employment_status' => $person?->employment_status];
            foreach (MemberContribution::DEDUCTION_TYPES as $type) {
                $row[$type] = (int) ($group->firstWhere('type', $type)?->total ?? 0);
            }

            return $row;
        })->sortBy('name')->values();

        return Inertia::render('Dashboard/Cooperative/Deductions/Index', [
            'year' => $year,
            'month' => $month,
            'years' => $years ?: [now()->year],
            'rows' => $rows,
            'typeLabels' => array_intersect_key(MemberContribution::TYPE_LABELS, array_flip(MemberContribution::DEDUCTION_TYPES)),
        ]);
    }

    public function import()
    {
        return Inertia::render('Dashboard/Cooperative/Deductions/Import', [
            'fields' => DeductionImportService::FIELDS,
            'typeLabels' => array_intersect_key(MemberContribution::TYPE_LABELS, array_flip(MemberContribution::DEDUCTION_TYPES)),
            'employees' => Customer::employees()->orderBy('name')->get(['id', 'name', 'nip', 'employee_type', 'employment_status']),
        ]);
    }

    /** Langkah 1: unggah file → daftar sheet + 40 baris pertama + tebakan judul/kolom. */
    public function upload(Request $request)
    {
        set_time_limit(600);
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:51200']]);
        $token = $this->files->put($request->file('file'), 'deductions');

        return $this->json(fn () => ['token' => $token] + $this->importer->layout($this->path($token)));
    }

    /** Ganti sheet atau baris judul. */
    public function layout(Request $request)
    {
        set_time_limit(600);
        $data = $request->validate(['token' => 'required|string', 'sheet' => 'required|string', 'header_from' => 'nullable|integer|min:1', 'header_to' => 'nullable|integer|min:1']);

        return $this->json(function () use ($data) {
            $path = $this->path($data['token']);
            $layout = $this->importer->layout($path, $data['sheet']);
            if (! empty($data['header_from'])) {
                $rows = $this->importer->rows($path, $data['sheet']);
                $from = (int) $data['header_from'];
                $to = max($from, (int) ($data['header_to'] ?? $from));
                $layout = array_merge($layout, ['header_from' => $from, 'header_to' => $to,
                    'columns' => $this->importer->columns($rows, $from, $to), 'mapping' => $this->importer->guessMapping($rows, $from, $to)]);
            }

            return $layout;
        });
    }

    /** Langkah 2: baca data dengan kolom pilihan → daftar nama untuk dicocokkan. */
    public function analyze(Request $request)
    {
        set_time_limit(600);

        return $this->json(function () use ($request) {
            $parsed = $this->parsed($request);

            return ['rows' => count($parsed), 'years' => array_values(array_unique(array_column($parsed, 'year'))), 'names' => $this->importer->names($parsed)];
        });
    }

    /** Langkah 3: pratinjau baru / sama / beda. */
    public function preview(Request $request)
    {
        set_time_limit(600);

        return $this->json(fn () => $this->importer->summary($this->importer->diff($this->parsed($request), $this->nameMap($request))));
    }

    /** Langkah 4: simpan. */
    public function commit(Request $request)
    {
        set_time_limit(600);
        $request->validate(['confirmed' => 'nullable|array', 'confirmed.*' => 'string']);

        return $this->json(function () use ($request) {
            $parsed = $this->parsed($request);
            $names = $this->nameMap($request);
            $result = $this->importer->commit($this->importer->diff($parsed, $names), $request->input('confirmed', []), $names, $parsed, $request->user()->id);
            $this->files->forget('deductions', $request->input('token'));

            return $result;
        });
    }

    private function parsed(Request $request): array
    {
        $data = $request->validate(['token' => 'required|string', 'sheet' => 'required|string', 'header_to' => 'required|integer|min:1', 'mapping' => 'required|array']);
        $mapping = array_map(fn ($v) => $v === null || $v === '' ? null : (int) $v, $data['mapping']);

        return $this->importer->parse($this->importer->rows($this->path($data['token']), $data['sheet']), (int) $data['header_to'], $mapping);
    }

    private function nameMap(Request $request): array
    {
        $names = (array) $request->input('names', []);
        $valid = Customer::whereIn('id', array_filter(array_map('intval', $names)))->pluck('id')->all();

        return array_map(fn ($v) => in_array((int) $v, $valid, true) ? (int) $v : null, $names);
    }

    private function path(string $token): string
    {
        $path = $this->files->path('deductions', $token);
        if (! $path) {
            throw new RuntimeException('File sudah kedaluwarsa. Unggah ulang.');
        }

        return $path;
    }

    private function json(callable $action)
    {
        try {
            return response()->json($action());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
