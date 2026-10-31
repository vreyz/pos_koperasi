<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShuAdjustment extends Model
{
    protected $fillable = ['shu_period_id', 'customer_id', 'base_override', 'amount', 'note'];

    protected $casts = [
        'base_override' => 'integer',
        'amount' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
