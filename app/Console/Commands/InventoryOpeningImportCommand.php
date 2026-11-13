<?php

namespace App\Console\Commands;

use App\Models\Warehouse;
use App\Services\Accounting\ReportService;
use App\Services\Inventory\OpeningStockImportService;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;

/** Ganti stok aplikasi dengan saldo awal dari berkas IMPORT_ITEM iPos (tanpa --apply hanya pratinjau). */
class InventoryOpeningImportCommand extends Command
{
    protected $signature = 'inventory:opening-import {path : Berkas IMPORT_ITEM (.xls/.xlsx)}
        {--date=2026-01-01 : Tanggal saldo awal}
        {--warehouse= : ID gudang (bawaan: gudang pertama)}
        {--apply : Simpan (tanpa ini hanya pratinjau)}
        {--force : Tetap jalan walau sudah ada mutasi stok transaksi nyata}';

    protected $description = 'Saldo awal stok dari berkas IMPORT_ITEM iPos (stok lama diganti)';

    public function handle(OpeningStockImportService $service, ReportService $reports): int
    {
        $plan = $service->plan($this->argument('path'));
        $rows = collect($plan['rows']);
        $own = $rows->whereNull('consignor');
        $this->line('Barang cocok       : '.$rows->count().' (titipan '.$rows->whereNotNull('consignor')->count().', penitip '.$rows->pluck('consignor')->filter()->unique()->count().')');
        $this->line('Baris satuan besar : '.$plan['skipped_units'].' (dilewati, stok ada di satuan dasar)');
        $this->line('Tidak cocok        : '.count($plan['unmatched']).($plan['unmatched'] ? ' (baris '.implode(', ', array_slice($plan['unmatched'], 0, 20)).')' : ''));
        $this->line('Qty milik koperasi : '.$own->sum('qty').', nilai harga beli Rp '.number_format($own->sum(fn ($r) => $r['qty'] * (int) $r['product']->buy_price), 0, ',', '.'));
        $this->line('Qty titipan        : '.$rows->whereNotNull('consignor')->sum('qty'));
        $this->line('Berstok tanpa harga beli: '.$own->filter(fn ($r) => $r['qty'] > 0 && (int) $r['product']->buy_price === 0)->count());
        $gl = $reports->accountBalance('11501', Carbon::parse($this->option('date'))->subDay()->toDateString());
        $this->line('Persediaan di neraca awal (11501): Rp '.number_format($gl, 0, ',', '.'));

        if (! $this->option('apply')) {
            $this->info('Pratinjau saja. Tambahkan --apply untuk menyimpan.');

            return self::SUCCESS;
        }
        if (($real = $service->realMovementCount()) > 0 && ! $this->option('force')) {
            $this->error("Sudah ada {$real} mutasi stok dari transaksi nyata; saldo awal akan menimpa stok. Pakai --force bila memang disengaja.");

            return self::FAILURE;
        }
        $warehouseId = (int) ($this->option('warehouse') ?: Warehouse::orderBy('sort_order')->orderBy('id')->value('id'));
        $stats = $service->apply($plan, $this->option('date'), $warehouseId);
        $this->info('Tersimpan: '.json_encode($stats));

        return self::SUCCESS;
    }
}
