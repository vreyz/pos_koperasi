<?php

namespace Tests\Feature\Cooperative;

use App\Models\Customer;
use App\Models\CustomerAlias;
use App\Models\ExternalSale;
use App\Models\MemberContribution;
use App\Models\ShuPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NameLinkTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $name): Customer
    {
        return Customer::create(['name' => $name, 'employee_type' => 'PNS', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
    }

    public function test_lists_names_from_sales_savings_and_aliases_and_relinks_them(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['employees-access', 'employees-edit']);
        $budi = $this->person('Budi Santoso');
        $citra = $this->person('Citra Lestari');
        ExternalSale::create(['year' => 2025, 'buyer_name' => 'BUDI S', 'amount' => 20000]);
        MemberContribution::create(['customer_id' => $citra->id, 'type' => 'wajib', 'period_year' => 2025, 'period_month' => 1, 'amount' => 100000, 'source' => 'import', 'source_name' => 'Budi Santosa']);
        CustomerAlias::create(['customer_id' => $citra->id, 'alias' => 'CITRA L']);
        $period = ShuPeriod::create(['year' => 2025, 'total_shu' => 0, 'savings_types' => MemberContribution::SAVINGS_TYPES]);
        $period->allocations()->create(['customer_id' => $citra->id, 'total_rounded' => 77000]);

        $this->actingAs($user)->get(route('name-links.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Cooperative/Names/Index')
                ->has('rows', 3)
                ->where('rows.0.name', 'BUDI S')
                ->where('rows.0.customer_id', null)
                ->where('rows.0.suggestions.0.id', $budi->id)
                ->where('shuYear', 2025));

        // Penjualan belum cocok → hubungkan; simpanan salah orang → pindahkan.
        $this->actingAs($user)->post(route('name-links.link'), ['source' => 'sales', 'name' => 'BUDI S', 'customer_id' => $budi->id])->assertSessionHas('success');
        $this->actingAs($user)->post(route('name-links.link'), ['source' => 'savings', 'name' => 'Budi Santosa', 'customer_id' => $budi->id])->assertSessionHas('success');

        $this->assertSame($budi->id, ExternalSale::first()->customer_id);
        $this->assertSame($budi->id, MemberContribution::first()->customer_id);
        $this->assertTrue(CustomerAlias::where('alias', 'BUDI S')->where('customer_id', $budi->id)->exists());
        $this->assertTrue(CustomerAlias::where('alias', 'BUDI SANTOSA')->where('customer_id', $budi->id)->exists());

        $this->actingAs($user)->post(route('name-links.unlink'), ['source' => 'sales', 'name' => 'BUDI S']);
        $this->assertNull(ExternalSale::first()->customer_id);
        $this->assertFalse(CustomerAlias::where('alias', 'BUDI S')->exists());

        // Simpanan tidak boleh diputus tanpa pemilik.
        $this->actingAs($user)->post(route('name-links.unlink'), ['source' => 'savings', 'name' => 'Budi Santosa'])->assertSessionHasErrors('source');
    }

    public function test_linking_requires_edit_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('employees-access');
        $this->actingAs($user)->post(route('name-links.link'), ['source' => 'alias', 'name' => 'X', 'customer_id' => 1])->assertForbidden();
    }
}
