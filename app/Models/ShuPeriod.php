<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShuPeriod extends Model
{
    protected $fillable = [
        'year',
        'total_shu',
        'base_per_person',
        'base_requires_savings',
        'base_for_ppnpn',
        'base_mode',
        'board_amount',
        'board_members',
        'portion_savings',
        'portion_purchases',
        'portion_loans',
        'portion_financing_dana',
        'portion_financing_barang',
        'savings_types',
        'savings_period',
        'sales_source',
        'rounding',
        'rounding_unit',
        'excluded_customer_ids',
        'status',
        'calculated_at',
        'finalized_at',
    ];

    /** Sama dengan default kolom, agar model baru langsung berperilaku seperti yang tersimpan. */
    protected $attributes = [
        'base_requires_savings' => true,
        'base_for_ppnpn' => true,
        'base_mode' => 'full',
        'savings_period' => 'year',
        'sales_source' => 'pos',
        'rounding' => 'round',
        'rounding_unit' => 1000,
        'status' => 'draft',
    ];

    protected $casts = [
        'year' => 'integer',
        'total_shu' => 'integer',
        'base_per_person' => 'integer',
        'base_requires_savings' => 'boolean',
        'base_for_ppnpn' => 'boolean',
        'rounding_unit' => 'integer',
        'board_amount' => 'integer',
        'board_members' => 'array',
        'portion_savings' => 'float',
        'portion_purchases' => 'float',
        'portion_loans' => 'float',
        'portion_financing_dana' => 'float',
        'portion_financing_barang' => 'float',
        'savings_types' => 'array',
        'excluded_customer_ids' => 'array',
        'calculated_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function allocations()
    {
        return $this->hasMany(ShuAllocation::class);
    }

    public function adjustments()
    {
        return $this->hasMany(ShuAdjustment::class);
    }

    public function isFinal(): bool
    {
        return $this->status === 'final';
    }
}
