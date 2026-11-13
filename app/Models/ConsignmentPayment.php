<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Instruksi Pembayaran hasil penjualan barang titipan ke penitip. */
class ConsignmentPayment extends Model
{
    protected $fillable = ['number', 'year', 'seq', 'consignor_id', 'date', 'period_from', 'period_to', 'items', 'total', 'sales_total', 'status', 'paid_at', 'cash_account', 'signer', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'period_from' => 'date', 'period_to' => 'date', 'paid_at' => 'date', 'items' => 'array', 'total' => 'integer', 'sales_total' => 'integer'];
    }

    public function consignor(): BelongsTo
    {
        return $this->belongsTo(Consignor::class);
    }
}
