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
        'financing_dana_amount',
        'financing_barang_amount',
        'months_paid',
        'savings_share',
        'purchase_share',
        'loan_share',
        'financing_dana_share',
        'financing_barang_share',
        'base_amount',
        'board_amount',
        'shu_savings',
        'shu_purchases',
        'shu_loans',
        'shu_financing_dana',
        'shu_financing_barang',
        'adjustment_amount',
        'adjustment_note',
        'total',
        'total_rounded',
    ];

    protected $casts = [
        'savings_amount' => 'integer',
        'purchase_amount' => 'integer',
        'loan_amount' => 'integer',
        'financing_dana_amount' => 'integer',
        'financing_barang_amount' => 'integer',
        'financing_dana_share' => 'float',
        'financing_barang_share' => 'float',
        'shu_financing_dana' => 'float',
        'shu_financing_barang' => 'float',
        'months_paid' => 'integer',
        'adjustment_amount' => 'integer',
        'savings_share' => 'float',
        'purchase_share' => 'float',
        'loan_share' => 'float',
        'base_amount' => 'integer',
        'board_amount' => 'integer',
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
