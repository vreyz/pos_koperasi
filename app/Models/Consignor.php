<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Penitip barang konsinyasi (bisa pegawai). Barangnya bukan milik koperasi. */
class Consignor extends Model
{
    protected $fillable = ['name', 'customer_id', 'phone', 'bank_name', 'bank_account', 'account_holder', 'notes', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ConsignmentMovement::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ConsignmentPayment::class);
    }
}
