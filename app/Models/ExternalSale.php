<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExternalSale extends Model
{
    protected $fillable = ['year', 'sale_date', 'buyer_name', 'customer_id', 'amount'];

    protected $casts = [
        'year' => 'integer',
        'sale_date' => 'date',
        'amount' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
