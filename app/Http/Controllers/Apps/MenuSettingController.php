<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Sembunyikan menu sidebar yang tidak dipakai koperasi (mis. Dine-In, WhatsApp).
 * Hanya menyembunyikan tampilan; izin akses tetap diatur di menu Role.
 */
class MenuSettingController extends Controller
{
    public static function hidden(): array
    {
        $value = json_decode((string) Setting::get('hidden_menus', '[]'), true);

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    public function edit()
    {
        return Inertia::render('Dashboard/Settings/Menus');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'hidden' => ['array'],
            'hidden.*' => ['string', 'max:255', 'regex:/^\/dashboard\//'],
        ]);
        // Halaman pengaturan menu & dashboard tidak boleh disembunyikan.
        $hidden = array_values(array_diff(array_unique($data['hidden'] ?? []), ['/dashboard', '/dashboard/settings/menus']));
        Setting::set('hidden_menus', json_encode($hidden));

        return back()->with('success', count($hidden).' menu disembunyikan.');
    }
}
