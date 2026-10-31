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
        'portion_savings',
        'portion_purchases',
        'portion_loans',
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
        'portion_savings' => 'float',
        'portion_purchases' => 'float',
        'portion_loans' => 'float',
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
