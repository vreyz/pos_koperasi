<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Financing extends Model
{
    public const TYPES = ['dana', 'barang'];

    public const TYPE_LABELS = ['dana' => 'Pembiayaan Dana', 'barang' => 'Pembiayaan Barang'];

    // Alur: pengajuan → diperiksa → direview → disetujui → berjalan (dicairkan) → lunas. Bisa ditolak di tahap mana pun.
    public const STATUS_LABELS = [
        'pengajuan' => 'Pengajuan',
        'diperiksa' => 'Diperiksa',
        'direview' => 'Direview',
        'disetujui' => 'Disetujui',
        'berjalan' => 'Berjalan',
        'lunas' => 'Lunas',
        'ditolak' => 'Ditolak',
    ];

    public const DISBURSED_STATUSES = ['berjalan', 'lunas'];

    protected $fillable = [
        'type', 'number', 'customer_id', 'status', 'applied_at', 'disbursed_at', 'item_name',
        'principal', 'margin_percent', 'margin_amount', 'admin_fee', 'tenor', 'installment', 'first_due_month',
        'transferred_amount', 'bank_name', 'bank_account', 'phone', 'family_name', 'family_relation', 'family_phone',
        'is_bad_debt', 'notes', 'rejection_reason',
        'created_by', 'checked_by', 'reviewed_by', 'approved_by', 'rejected_by',
        'checked_at', 'reviewed_at', 'approved_at', 'rejected_at',
    ];

    protected $casts = [
        'applied_at' => 'date:Y-m-d',
        'disbursed_at' => 'date:Y-m-d',
        'first_due_month' => 'date:Y-m-d',
        'principal' => 'integer',
        'margin_percent' => 'float',
        'margin_amount' => 'integer',
        'admin_fee' => 'integer',
        'tenor' => 'integer',
        'installment' => 'integer',
        'transferred_amount' => 'integer',
        'is_bad_debt' => 'boolean',
        'checked_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function installments()
    {
        return $this->hasMany(FinancingInstallment::class)->orderBy('seq');
    }

    public function checker()
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function total(): int
    {
        return $this->principal + $this->margin_amount;
    }

    public function isDisbursed(): bool
    {
        return in_array($this->status, self::DISBURSED_STATUSES, true);
    }
}
