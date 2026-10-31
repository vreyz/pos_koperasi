<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancingInstallment extends Model
{
    protected $fillable = ['financing_id', 'seq', 'due_month', 'amount', 'paid_at', 'paid_source', 'note'];

    protected $casts = [
        'due_month' => 'date:Y-m-d',
        'paid_at' => 'date:Y-m-d',
        'amount' => 'integer',
        'seq' => 'integer',
    ];

    public function financing()
    {
        return $this->belongsTo(Financing::class);
    }
}
