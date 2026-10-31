<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    /**
     * fillable
     *
     * @var array
     */
    public const EMPLOYEE_TYPES = ['PNS', 'PPPK', 'PPNPN', 'LAINNYA'];

    protected $fillable = [
        'name',
        'nip',
        'employee_type',
        'rank',
        'grade',
        'position',
        'work_unit',
        'employment_status',
        'no_telp',
        'address',
        'is_loyalty_member',
        'member_code',
        'loyalty_tier',
        'loyalty_points',
        'loyalty_total_spent',
        'loyalty_transaction_count',
        'loyalty_member_since',
        'last_purchase_at',
        'province_id',
        'province_name',
        'regency_id',
        'regency_name',
        'district_id',
        'district_name',
        'village_id',
        'village_name',
    ];

    protected $casts = [
        'is_loyalty_member' => 'boolean',
        'loyalty_points' => 'integer',
        'loyalty_total_spent' => 'integer',
        'loyalty_transaction_count' => 'integer',
        'loyalty_member_since' => 'datetime',
        'last_purchase_at' => 'datetime',
    ];

    public function scopeEmployees($query)
    {
        return $query->whereNotNull('employee_type');
    }

    public function aliases()
    {
        return $this->hasMany(CustomerAlias::class);
    }

    public function contributions()
    {
        return $this->hasMany(MemberContribution::class);
    }

    public function salesReturns()
    {
        return $this->hasMany(SalesReturn::class);
    }

    public function customerCredits()
    {
        return $this->hasMany(CustomerCredit::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function loyaltyPointHistories()
    {
        return $this->hasMany(LoyaltyPointHistory::class);
    }

    public function vouchers()
    {
        return $this->hasMany(CustomerVoucher::class);
    }

    public function receivables()
    {
        return $this->hasMany(Receivable::class);
    }

    public function segmentMemberships()
    {
        return $this->hasMany(CustomerSegmentMembership::class);
    }

    public function segments()
    {
        return $this->belongsToMany(CustomerSegment::class, 'customer_segment_memberships')
            ->withPivot(['source', 'matched_at'])
            ->withTimestamps();
    }
}
