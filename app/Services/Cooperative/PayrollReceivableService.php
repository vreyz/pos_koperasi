<?php

namespace App\Services\Cooperative;

use App\Models\Customer;
use App\Models\MemberContribution;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Metode bayar koperasi di kasir & kantin. "Potong Gaji" mencatat piutang pegawai (sumber
 * potong_gaji) yang dilunasi dari kolom Potong Gaji Koperasi pada impor Angsuran & Potongan.
 */
class PayrollReceivableService
{
    public const POTONG_GAJI = 'potong_gaji';

    /** Metode non-tunai yang langsung lunas (dikonfirmasi kasir, tanpa payment gateway). */
    public const MANUAL_PAID = ['card', 'qris_manual'];

    public const LABELS = [
        'cash' => 'Tunai',
        'qris_manual' => 'QRIS',
        'qris' => 'QRIS',
        'bank_transfer' => 'Transfer',
        'card' => 'Kartu Debit/Kredit',
        self::POTONG_GAJI => 'Potong Gaji',
    ];

    public function ensureEmployee(?Customer $customer): Customer
    {
        if (! $customer || ! $customer->employee_type) {
            throw ValidationException::withMessages(['customer_id' => 'Potong Gaji hanya untuk pegawai. Pilih pelanggan yang terdaftar di Data Pegawai.']);
        }

        return $customer;
    }

    /** Piutang potong gaji, jatuh tempo akhir bulan transaksi (dipotong pada gaji bulan itu). */
    public function open(Transaction $transaction, Customer $customer): Receivable
    {
        return Receivable::create([
            'customer_id' => $customer->id,
            'transaction_id' => $transaction->id,
            'invoice' => $transaction->invoice,
            'total' => (int) $transaction->grand_total,
            'paid' => 0,
            'due_date' => ($transaction->created_at ?? now())->copy()->endOfMonth()->toDateString(),
            'status' => 'unpaid',
            'source' => self::POTONG_GAJI,
        ]);
    }

    /**
     * Lunasi piutang potong gaji dari total Potong Gaji Koperasi yang sudah diimpor (urut piutang
     * terlama). Berbasis selisih total, jadi aman dijalankan berulang (tidak membayar dua kali).
     *
     * @param  list<int>|null  $customerIds
     */
    public function allocate(?array $customerIds = null): int
    {
        $count = 0;
        $pgTotals = MemberContribution::where('type', MemberContribution::TYPE_PG)
            ->when($customerIds !== null, fn ($q) => $q->whereIn('customer_id', $customerIds))
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(amount) as total')->pluck('total', 'customer_id');

        foreach ($pgTotals as $customerId => $pgTotal) {
            DB::transaction(function () use ($customerId, $pgTotal, &$count) {
                $allocated = (int) ReceivablePayment::where('method', self::POTONG_GAJI)
                    ->whereHas('receivable', fn ($q) => $q->where('customer_id', $customerId))->sum('amount');
                $available = (int) $pgTotal - $allocated;
                if ($available <= 0) {
                    return;
                }
                $open = Receivable::where('customer_id', $customerId)->where('source', self::POTONG_GAJI)
                    ->where('status', '!=', 'paid')->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
                foreach ($open as $receivable) {
                    if ($available <= 0) {
                        break;
                    }
                    $amount = (int) min($available, $receivable->remaining);
                    if ($amount <= 0) {
                        continue;
                    }
                    ReceivablePayment::create(['receivable_id' => $receivable->id, 'paid_at' => now()->toDateString(), 'amount' => $amount,
                        'method' => self::POTONG_GAJI, 'note' => 'Dilunasi dari Potong Gaji Koperasi']);
                    $receivable->paid = (int) $receivable->paid + $amount;
                    $receivable->status = $receivable->paid >= $receivable->total ? 'paid' : 'partial';
                    $receivable->save();
                    $receivable->transaction?->update(['payment_status' => $receivable->status === 'paid' ? 'paid' : 'unpaid']);
                    $available -= $amount;
                    $count++;
                }
            });
        }

        return $count;
    }
}
