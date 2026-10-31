<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\BoardDecree;
use App\Models\Customer;
use App\Services\Cooperative\BoardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Menu Pengurus Koperasi: upload SK, daftar pengurus tersimpan bersama file SK-nya. */
class BoardController extends Controller
{
    private const FILE_RULE = 'mimes:pdf,docx,doc,jpg,jpeg,png|max:20480';

    public function index()
    {
        $decrees = BoardDecree::with('members.customer:id,name,nip,employee_type')->orderByDesc('effective_from')->orderByDesc('id')->get();
        $today = now()->toDateString();
        $current = $decrees->first(fn ($d) => $d->effective_from->toDateString() <= $today && (! $d->effective_until || $d->effective_until->toDateString() >= $today));

        return Inertia::render('Dashboard/Cooperative/Board/Index', [
            'decrees' => $decrees,
            'currentId' => $current?->id,
            'positions' => BoardService::POSITIONS,
            'employees' => Customer::employees()->orderBy('name')->get(['id', 'name', 'nip', 'employee_type', 'employment_status']),
        ]);
    }

    /** Baca nama pengurus dari SK yang baru dipilih (belum disimpan). */
    public function preview(Request $request, BoardService $board)
    {
        $request->validate(['file' => 'nullable|file|'.self::FILE_RULE, 'text' => 'nullable|string|max:500000']);

        return response()->json($board->detect($board->text($request->file('file'), $request->input('text'))));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, true, null);
        DB::transaction(function () use ($request, $data) {
            $decree = BoardDecree::create($data + ['created_by' => $request->user()->id]);
            $this->saveFile($request, $decree);
            $this->saveMembers($decree, $data['members']);
        });

        return back()->with('success', 'SK pengurus dan daftar pengurus disimpan.');
    }

    public function update(Request $request, BoardDecree $decree)
    {
        $data = $this->validated($request, false, $decree->id);
        DB::transaction(function () use ($request, $decree, $data) {
            $decree->update($data);
            $this->saveFile($request, $decree);
            $this->saveMembers($decree, $data['members']);
        });

        return back()->with('success', 'SK pengurus diperbarui.');
    }

    public function destroy(BoardDecree $decree)
    {
        if ($decree->file_path) {
            Storage::disk('local')->delete($decree->file_path);
        }
        $decree->delete();

        return back()->with('success', 'SK pengurus dihapus.');
    }

    public function file(BoardDecree $decree)
    {
        abort_unless($decree->file_path && Storage::disk('local')->exists($decree->file_path), 404);

        return Storage::disk('local')->response($decree->file_path, $decree->file_name);
    }

    private function validated(Request $request, bool $fileRequired, ?int $ignoreId): array
    {
        $data = $request->validate([
            'number' => ['nullable', 'string', 'max:120', Rule::unique('board_decrees', 'number')->ignore($ignoreId)],
            'decree_date' => 'nullable|date',
            'effective_from' => 'required|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'notes' => 'nullable|string|max:1000',
            'file' => ($fileRequired ? 'required' : 'nullable').'|file|'.self::FILE_RULE,
            'members' => 'required|array|min:1',
            'members.*.customer_id' => 'required|integer|distinct|exists:customers,id',
            'members.*.position' => 'nullable|string|max:80',
        ], [
            'members.required' => 'Isi minimal satu pengurus.',
            'members.*.customer_id.distinct' => 'Nama pengurus ada yang dobel.',
            'file.required' => 'Pilih file SK.',
            'number.unique' => 'SK dengan nomor ini sudah tersimpan. Ubah SK yang ada bila ingin mengganti.',
        ]);
        unset($data['file']);

        return $data;
    }

    private function saveFile(Request $request, BoardDecree $decree): void
    {
        if (! $request->hasFile('file')) {
            return;
        }
        if ($decree->file_path) {
            Storage::disk('local')->delete($decree->file_path);
        }
        $file = $request->file('file');
        $decree->update([
            'file_path' => $file->store('board-decrees', 'local'),
            'file_name' => $file->getClientOriginalName(),
        ]);
    }

    private function saveMembers(BoardDecree $decree, array $members): void
    {
        $decree->members()->delete();
        foreach (array_values($members) as $i => $member) {
            $decree->members()->create(['customer_id' => $member['customer_id'], 'position' => $member['position'] ?? null, 'sort' => $i]);
        }
    }
}
