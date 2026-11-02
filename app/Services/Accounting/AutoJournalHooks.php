<?php

namespace App\Services\Accounting;

use App\Models\Financing;
use App\Models\FinancingInstallment;
use App\Models\GoodsReceiving;
use App\Models\GoodsReceivingItem;
use App\Models\MemberContribution;
use App\Models\PayablePayment;
use App\Models\ReceivablePayment;
use App\Models\SalesReturn;
use App\Models\ShiftCashMovement;
use App\Models\ShuPeriod;
use App\Models\StockMutation;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnItem;
use App\Models\StockOpname;
use App\Models\Transaction;
use App\Models\TransactionTender;
use Illuminate\Support\Carbon;

/**
 * Pasang pemicu jurnal otomatis pada model sumber. Pemicu hanya menandai data yang berubah;
 * jurnalnya dibuat setelah respons terkirim, jadi kasir dan menu lain tidak ikut melambat
 * dan tidak gagal bila ada masalah pembukuan.
 */
class AutoJournalHooks
{
    /** Untuk perubahan massal (insert/update/delete tanpa model, mis. upload): sinkronkan semua sumber setelah request. */
    public static function bulkChanged(): void
    {
        AutoJournalService::markDirty('all', '*');
    }

    public static function register(): void
    {
        // Antrian latar (queue worker) tidak pernah "selesai request": jurnalkan setiap tugas selesai.
        \Illuminate\Support\Facades\Queue::after(fn () => app(AutoJournalService::class)->flushDirty());

        $mark = fn (string $scope, $id) => $id !== null && $id !== '' ? AutoJournalService::markDirty($scope, (string) $id) : null;
        $day = fn ($date) => $date ? Carbon::parse($date)->toDateString() : null;

        // saved = buat/ubah lewat save(); updated juga dipicu increment()/decrement(); deleted = hapus.
        $on = function (string $model, callable $fn) {
            $model::saved($fn);
            $model::updated($fn);
            $model::deleted($fn);
        };

        $on(Transaction::class, fn ($t) => $mark('sales', $day($t->created_at)));
        $on(TransactionTender::class, fn ($t) => $mark('sales', $day($t->transaction?->created_at)));
        $on(GoodsReceiving::class, fn ($g) => $mark('gr', $g->id));
        $on(GoodsReceivingItem::class, fn ($i) => $mark('gr', $i->goods_receiving_id));
        $on(SalesReturn::class, fn ($r) => $mark('return', $r->id));
        $on(StockOpname::class, fn ($s) => $mark('opname', $s->id));
        $on(PayablePayment::class, fn ($p) => $mark('payable', $p->id));
        $on(ReceivablePayment::class, fn ($p) => $mark('receivable', $p->id));
        $on(Financing::class, fn ($f) => $mark('financing', $f->id));
        $on(FinancingInstallment::class, fn ($i) => $mark('financing', $i->financing_id));
        $on(ShuPeriod::class, fn ($s) => $mark('shu', $s->id));
        $on(SupplierReturn::class, fn ($r) => $mark('supplier_return', $r->id));
        $on(SupplierReturnItem::class, fn ($i) => $mark('supplier_return', $i->supplier_return_id));
        $on(StockMutation::class, fn ($m) => $m->reference_type === 'product_create' ? $mark('init', $m->id) : null);
        $on(ShiftCashMovement::class, fn ($c) => $mark('cash_move', $c->id));
        $on(MemberContribution::class, function ($c) use ($mark) {
            in_array($c->source, ['import', 'potong_gaji'], true)
                ? $mark('recap', sprintf('%04d-%02d', $c->period_year, $c->period_month))
                : $mark('contribution', $c->id);
        });
    }
}
