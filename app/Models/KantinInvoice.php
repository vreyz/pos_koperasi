<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Invoice kantin mingguan: penjualan dikurangi fee koperasi dan potongan, dibayar ke penjaga kantin. */
class KantinInvoice extends Model
{
    protected $fillable = ['number', 'year', 'seq', 'date', 'period_from', 'period_to', 'sales', 'fee_percent', 'deductions', 'total', 'payee_name', 'payee_bank', 'payee_account', 'signer', 'status', 'paid_at', 'cash_account', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'period_from' => 'date', 'period_to' => 'date', 'paid_at' => 'date', 'deductions' => 'array', 'sales' => 'integer', 'total' => 'integer', 'fee_percent' => 'float'];
    }

    public function fee(): int
    {
        return (int) round($this->sales * $this->fee_percent / 100);
    }
}
