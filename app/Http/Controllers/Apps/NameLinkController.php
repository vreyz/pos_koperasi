<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAlias;
use App\Models\ExternalSale;
use App\Models\MemberContribution;
use App\Models\ShuPeriod;
use App\Services\Cooperative\MemberSync;
use App\Services\Cooperative\NameMatcher;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Penghubung nama: nama di penjualan Excel, nama di file simpanan, dan alias → pegawai,
 * beserta hasil SHU-nya. Koreksi di sini langsung memindahkan data dan diingat sebagai alias.
 */
class NameLinkController extends Controller
{
    public function index(Request $request)
    {
        $shuPeriods = ShuPeriod::orderByDesc('year')->get(['id', 'year']);
        $shuYear = (int) $request->query('shu_year', $shuPeriods->first()?->year);
        $shuTotals = ShuPeriod::where('year', $shuYear)->first()?->allocations()->pluck('total_rounded', 'customer_id') ?? collect();

        $rows = collect();

        ExternalSale::query()
            ->selectRaw('buyer_name, customer_id, MIN(year) as first_year, MAX(year) as last_year, SUM(amount) as total, COUNT(*) as entries')
            ->groupBy('buyer_name', 'customer_id')
            ->get()
            ->each(fn ($r) => $rows->push([
                'source' => 'sales',
                'name' => $r->buyer_name,
                'customer_id' => $r->customer_id,
                'period' => $r->first_year == $r->last_year ? (string) $r->first_year : "{$r->first_year}–{$r->last_year}",
                'total' => (int) $r->total,
            ]));

        MemberContribution::query()
            ->whereNotNull('source_name')
            ->selectRaw('source_name, customer_id, MIN(period_year) as first_year, MAX(period_year) as last_year, SUM(amount) as total')
            ->groupBy('source_name', 'customer_id')
            ->get()
            ->each(fn ($r) => $rows->push([
                'source' => 'savings',
                'name' => $r->source_name,
                'customer_id' => $r->customer_id,
                'period' => $r->first_year == $r->last_year ? (string) $r->first_year : "{$r->first_year}–{$r->last_year}",
                'total' => (int) $r->total,
            ]));

        CustomerAlias::query()->get(['alias', 'customer_id'])->each(fn ($a) => $rows->push([
            'source' => 'alias',
            'name' => $a->alias,
            'customer_id' => $a->customer_id,
            'period' => null,
            'total' => null,
        ]));

        // Pembeli yang terhubung ke customer biasa (bukan pegawai) dianggap belum cocok ke pegawai.
        $nonEmployees = Customer::whereIn('id', $rows->pluck('customer_id')->filter()->unique())->whereNull('employee_type')->pluck('name', 'id');
        $matcher = null;
        $rows = $rows->map(function (array $row) use (&$matcher, $shuTotals, $nonEmployees) {
            $row['buyer_customer'] = $row['customer_id'] ? ($nonEmployees[$row['customer_id']] ?? null) : null;
            if ($row['buyer_customer'] !== null) {
                $row['customer_id'] = null;
            }
            if (! $row['customer_id']) {
                $matcher ??= new NameMatcher;
                $row['suggestions'] = $matcher->suggestions($row['name']);
            }
            $row['shu'] = $row['customer_id'] ? ($shuTotals[$row['customer_id']] ?? null) : null;

            return $row;
        })->sortBy([fn ($a, $b) => (bool) $a['customer_id'] <=> (bool) $b['customer_id'], fn ($a, $b) => strcasecmp($a['name'], $b['name'])])->values();

        return Inertia::render('Dashboard/Cooperative/Names/Index', [
            'rows' => $rows,
            'employees' => Customer::employees()->orderBy('name')->get(['id', 'name', 'nip', 'employee_type', 'employment_status']),
            'shuPeriods' => $shuPeriods,
            'shuYear' => $shuYear ?: null,
        ]);
    }

    public function link(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(['sales', 'savings', 'alias'])],
            'name' => ['required', 'string', 'max:255'],
            'customer_id' => ['required', Rule::exists('customers', 'id')->whereNotNull('employee_type')],
        ]);
        $customerId = (int) $data['customer_id'];

        $moved = match ($data['source']) {
            'sales' => ExternalSale::where('buyer_name', $data['name'])->update(['customer_id' => $customerId]),
            'savings' => MemberContribution::where('source_name', $data['name'])->update(['customer_id' => $customerId]),
            'alias' => 0,
        };
        $data['source'] === 'alias'
            ? CustomerAlias::updateOrCreate(['alias' => NameMatcher::normalize($data['name'])], ['customer_id' => $customerId])
            : NameMatcher::remember($data['name'], $customerId);

        $name = Customer::find($customerId)->name;

        return back()->with('success', "\"{$data['name']}\" dihubungkan ke {$name}".($moved ? " ({$moved} baris data dipindahkan)" : '').'. Hitung ulang SHU agar hasilnya ikut berubah.');
    }

    public function unlink(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(['sales', 'alias'])],
            'name' => ['required', 'string', 'max:255'],
        ]);

        CustomerAlias::where('alias', NameMatcher::normalize($data['name']))->delete();
        if ($data['source'] === 'sales') {
            ExternalSale::where('buyer_name', $data['name'])->update(['customer_id' => null]);
            app(MemberSync::class)->syncBuyers(); // kembali jadi pembeli umum
        }

        return back()->with('success', "Hubungan \"{$data['name']}\" diputus. Hitung ulang SHU agar hasilnya ikut berubah.");
    }
}
