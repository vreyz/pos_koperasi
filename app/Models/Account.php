<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = ['code', 'name', 'group', 'normal_balance', 'report_line', 'division', 'is_cash', 'is_active', 'description', 'sort'];

    protected function casts(): array
    {
        return ['is_cash' => 'boolean', 'is_active' => 'boolean', 'sort' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** Akun laba rugi (ditutup ke Dana Cadangan Tahun Berjalan setiap akhir tahun). */
    public function isIncomeStatement(): bool
    {
        return in_array($this->group, ['pendapatan', 'beban_pokok', 'beban_usaha', 'pajak'], true);
    }

    public static function idsByCode(): array
    {
        return static::pluck('id', 'code')->all();
    }
}
