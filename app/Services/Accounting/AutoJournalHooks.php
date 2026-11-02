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
use App\Models\ShuPeriod;
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
    public static function register(): void
    {
        $mark = fn (string $scope, $id) => $id !== null && $id !== '' ? AutoJournalService::markDirty($scope, (string) $id) : null;
        $day = fn ($date) => $date ? Carbon::parse($date)->toDateString() : null;

        $on = function (string $model, callable $fn) {
            $model::saved($fn);
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
        $on(MemberContribution::class, function ($c) use ($mark) {
            in_array($c->source, ['import', 'potong_gaji'], true)
                ? $mark('recap', sprintf('%04d-%02d', $c->period_year, $c->period_month))
                : $mark('contribution', $c->id);
        });
    }
}
