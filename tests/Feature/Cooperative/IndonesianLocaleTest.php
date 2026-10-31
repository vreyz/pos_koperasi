<?php

namespace Tests\Feature\Cooperative;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndonesianLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_stays_indonesian_for_english_browsers_and_validation_is_translated(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['savings-access', 'savings-create']);

        $this->actingAs($user)
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->post(route('savings.store'), [])
            ->assertSessionHasErrors(['customer_id' => 'Pegawai/pelanggan wajib diisi.']);

        $this->assertSame('id', app()->getLocale());
    }
}
