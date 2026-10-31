<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShuPeriod extends Model
{
    protected $fillable = [
        'year',
        'total_shu',
        'base_per_person',
        'portion_savings',
        'portion_purchases',
        'portion_loans',
        'savings_types',
        'savings_period',
        'sales_source',
        'rounding',
        'excluded_customer_ids',
        'status',
        'calculated_at',
        'finalized_at',
    ];

    protected $casts = [
        'year' => 'integer',
        'total_shu' => 'integer',
        'base_per_person' => 'integer',
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

    public function isFinal(): bool
    {
        return $this->status === 'final';
    }
}
