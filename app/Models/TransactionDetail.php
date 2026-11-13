<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionDetail extends Model
{
    use HasFactory;

    /** Barang titipan: catat penitip & bagian penitip (harga titip x jumlah dasar) saat terjual. */
    protected static function booted(): void
    {
        static::creating(function (TransactionDetail $detail) {
            if ($detail->consignor_id || ! $detail->product_id) {
                return;
            }
            $product = Product::query()->select(['id', 'consignor_id', 'buy_price'])->find($detail->product_id);
            if ($product?->consignor_id) {
                $baseQty = (float) $detail->qty * (float) ($detail->conversion_factor ?: 1);
                $detail->consignor_id = $product->consignor_id;
                $detail->consignment_cost = (int) round($baseQty * (int) $product->buy_price);
            }
        });
    }

    /**
     * fillable
     *
     * @var array
     */
    protected $fillable = [
        'transaction_id',
        'product_id',
        'consignor_id',
        'consignment_cost',
        'unit_id',
        'conversion_factor',
        'qty',
        'base_unit_price',
        'unit_price',
        'price',
        'discount_total',
        'product_batch_id',
        'pricing_rule_id',
        'pricing_rule_name',
        'pricing_rule_kind',
        'pricing_group_key',
        'pricing_group_label',
    ];

    protected $casts = [
        'qty' => 'integer',
        'base_unit_price' => 'integer',
        'unit_price' => 'integer',
        'price' => 'integer',
        'discount_total' => 'integer',
        'pricing_rule_id' => 'integer',
        'pricing_rule_kind' => 'string',
        'conversion_factor' => 'decimal:4',
    ];

    /**
     * Base-unit quantity consumed/returned by this line: selling qty x conversion factor.
     */
    public function baseQuantity(): int
    {
        return (int) round($this->qty * (float) ($this->conversion_factor ?? 1));
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function pricingRule()
    {
        return $this->belongsTo(PricingRule::class);
    }

    public function salesReturnItems()
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function batchAllocations()
    {
        return $this->hasMany(TransactionDetailBatchAllocation::class);
    }
}
