<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberContribution extends Model
{
    public const TYPE_POKOK = 'pokok';

    public const TYPE_WAJIB = 'wajib';

    public const TYPE_SUKARELA = 'sukarela';

    public const TYPE_ANGSURAN = 'angsuran';

    public const SAVINGS_TYPES = [self::TYPE_POKOK, self::TYPE_WAJIB, self::TYPE_SUKARELA];

    public const TYPES = [self::TYPE_POKOK, self::TYPE_WAJIB, self::TYPE_SUKARELA, self::TYPE_ANGSURAN];

    public const TYPE_LABELS = [
        self::TYPE_POKOK => 'Simpanan Pokok',
        self::TYPE_WAJIB => 'Simpanan Wajib',
        self::TYPE_SUKARELA => 'Simpanan Sukarela',
        self::TYPE_ANGSURAN => 'Angsuran Pinjaman',
    ];

    public const SOURCES = ['manual', 'potong_gaji', 'tunai', 'transfer', 'import'];

    protected $fillable = [
        'customer_id',
        'type',
        'period_year',
        'period_month',
        'amount',
        'source',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'period_year' => 'integer',
        'period_month' => 'integer',
        'amount' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
