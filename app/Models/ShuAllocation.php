<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShuAllocation extends Model
{
    protected $fillable = [
        'shu_period_id',
        'customer_id',
        'savings_amount',
        'purchase_amount',
        'loan_amount',
        'months_paid',
        'savings_share',
        'purchase_share',
        'loan_share',
        'base_amount',
        'shu_savings',
        'shu_purchases',
        'shu_loans',
        'adjustment_amount',
        'adjustment_note',
        'total',
        'total_rounded',
    ];

    protected $casts = [
        'savings_amount' => 'integer',
        'purchase_amount' => 'integer',
        'loan_amount' => 'integer',
        'months_paid' => 'integer',
        'adjustment_amount' => 'integer',
        'savings_share' => 'float',
        'purchase_share' => 'float',
        'loan_share' => 'float',
        'base_amount' => 'integer',
        'shu_savings' => 'float',
        'shu_purchases' => 'float',
        'shu_loans' => 'float',
        'total' => 'float',
        'total_rounded' => 'integer',
    ];

    public function period()
    {
        return $this->belongsTo(ShuPeriod::class, 'shu_period_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
