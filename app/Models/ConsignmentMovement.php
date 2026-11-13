<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Titip masuk (in) / tarik keluar (out) barang konsinyasi. */
class ConsignmentMovement extends Model
{
    protected $fillable = ['consignor_id', 'product_id', 'warehouse_id', 'type', 'qty', 'date', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date', 'qty' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
