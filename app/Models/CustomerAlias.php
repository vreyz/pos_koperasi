<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerAlias extends Model
{
    protected $fillable = ['customer_id', 'alias'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
