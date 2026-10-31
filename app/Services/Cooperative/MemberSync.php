<?php

namespace App\Services\Cooperative;

use App\Models\Customer;
use App\Models\ExternalSale;
use App\Models\MemberContribution;

/**
 * - Yang pernah membayar simpanan pokok/wajib/sukarela = anggota koperasi (member).
 * - Setiap nama pembeli di data penjualan Excel ada sebagai customer; pembeli yang
 *   bukan pegawai menjadi customer biasa (non-member, tidak ikut SHU).
 */
class MemberSync
{
    /**
     * @return array{members: int, buyers_created: int, buyers_linked: int}
     */
    public function run(): array
    {
        return ['members' => $this->syncMembers()] + $this->syncBuyers();
    }

    public function syncMembers(): int
    {
        $since = MemberContribution::query()
            ->whereIn('type', MemberContribution::SAVINGS_TYPES)
            ->where('amount', '>', 0)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, MIN(period_year * 100 + period_month) as first_period')
            ->pluck('first_period', 'customer_id');

        $count = 0;
        Customer::whereIn('id', $since->keys())
            ->where(fn ($q) => $q->where('is_loyalty_member', false)->orWhereNull('member_code'))
            ->get()
            ->each(function (Customer $customer) use ($since, &$count) {
                $period = (int) $since[$customer->id];
                $code = $customer->member_code ?: ($customer->nip ?: 'AGT-'.str_pad((string) $customer->id, 5, '0', STR_PAD_LEFT));
                if (Customer::where('member_code', $code)->whereKeyNot($customer->id)->exists()) {
                    $code = 'AGT-'.str_pad((string) $customer->id, 5, '0', STR_PAD_LEFT);
                }
                $customer->update([
                    'is_loyalty_member' => true,
                    'member_code' => $code,
                    'loyalty_member_since' => $customer->loyalty_member_since ?? sprintf('%04d-%02d-01', intdiv($period, 100), $period % 100),
                ]);
                $count++;
            });

        return $count;
    }

    /**
     * @return array{buyers_created: int, buyers_linked: int}
     */
    public function syncBuyers(): array
    {
        $created = 0;
        $linked = 0;
        $matcher = new NameMatcher;
        $byName = Customer::whereNull('employee_type')->get(['id', 'name'])
            ->mapWithKeys(fn ($c) => [NameMatcher::normalize($c->name) => $c->id]);

        foreach (ExternalSale::whereNull('customer_id')->distinct()->pluck('buyer_name') as $name) {
            $key = NameMatcher::normalize($name);
            if ($key === '') {
                continue;
            }
            $customerId = $matcher->match($name) ?? $byName[$key] ?? null;
            if (! $customerId) {
                $customerId = Customer::create(['name' => trim($name), 'no_telp' => 0, 'address' => ''])->id;
                $byName[$key] = $customerId;
                $created++;
            }
            $linked += ExternalSale::whereNull('customer_id')->where('buyer_name', $name)->update(['customer_id' => $customerId]);
        }

        return ['buyers_created' => $created, 'buyers_linked' => $linked];
    }
}
