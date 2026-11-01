<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemberContribution extends Model
{
    public const TYPE_POKOK = 'pokok';

    public const TYPE_WAJIB = 'wajib';

    public const TYPE_SUKARELA = 'sukarela';

    public const TYPE_ANGSURAN = 'angsuran';

    public const TYPE_PINJAMAN = 'pinjaman'; // kolom "Simpanan Koperasi – Pinjaman" di sheet potongan gaji

    public const TYPE_PG = 'pg'; // Potong Gaji Koperasi: pelunasan utang belanja/kantin

    public const SAVINGS_TYPES = [self::TYPE_POKOK, self::TYPE_WAJIB, self::TYPE_SUKARELA];

    /** Urutan kolom menu Angsuran & Potongan Koperasi (seperti sheet potongan gaji). */
    public const DEDUCTION_TYPES = [self::TYPE_PINJAMAN, self::TYPE_WAJIB, self::TYPE_POKOK, self::TYPE_SUKARELA, self::TYPE_ANGSURAN, self::TYPE_PG];

    public const TYPES = [self::TYPE_POKOK, self::TYPE_WAJIB, self::TYPE_SUKARELA, self::TYPE_ANGSURAN, self::TYPE_PINJAMAN, self::TYPE_PG];

    public const TYPE_LABELS = [
        self::TYPE_POKOK => 'Simpanan Pokok',
        self::TYPE_WAJIB => 'Simpanan Wajib',
        self::TYPE_SUKARELA => 'Simpanan Sukarela',
        self::TYPE_ANGSURAN => 'Angsuran Pinjaman',
        self::TYPE_PINJAMAN => 'Simpanan Pinjaman',
        self::TYPE_PG => 'Potong Gaji Koperasi',
    ];

    public const SOURCES = ['manual', 'potong_gaji', 'tunai', 'transfer', 'import'];

    protected $fillable = [
        'customer_id',
        'type',
        'period_year',
        'period_month',
        'amount',
        'source',
        'source_name',
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
