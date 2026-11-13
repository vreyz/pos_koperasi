<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\ConsignmentMovement;
use App\Models\Consignor;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockMutation;
use App\Models\Warehouse;
use App\Services\Inventory\OpeningStockImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Saldo awal stok dari berkas IMPORT_ITEM iPos: stok diganti, titipan jadi milik penitip. */
class OpeningStockImportTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $sku, int $stock, int $buy = 1000): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Umum'], ['description' => 'Umum', 'image' => '']);

        return Product::create(['title' => 'Barang '.$sku, 'barcode' => $sku, 'sku' => $sku, 'category_id' => $cat->id,
            'buy_price' => $buy, 'sell_price' => $buy + 500, 'stock' => $stock, 'tax_rate' => 0]);
    }

    private function file(array $rows): string
    {
        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray(array_merge([['KODE ITEM', 'BARCODE', 'NAMA ITEM', 'KONVERSI SATUAN DASAR', 'HARGA POKOK', 'STOK', 'STOK MINIMUM', 'KODE SUPPLIER', 'KETERANGAN']], $rows));
        $path = tempnam(sys_get_temp_dir(), 'item').'.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    public function test_opening_stock_replaces_imported_stock_and_registers_consignments(): void
    {
        $wh = Warehouse::factory()->create();
        $a = $this->product('0001', 50, 2000);
        $b = $this->product('0002', 9);
        $c = $this->product('0003', 4);
        $later = $this->product('0099', 30); // barang baru sepanjang tahun: tidak ada di saldo awal
        foreach ([$a, $b, $c, $later] as $p) {
            $p->warehouses()->attach($wh->id, ['stock' => $p->stock]);
            StockMutation::create(['product_id' => $p->id, 'warehouse_id' => $wh->id, 'reference_type' => 'ipos_import', 'mutation_type' => 'in', 'qty' => $p->stock, 'stock_before' => 0, 'stock_after' => $p->stock]);
        }
        $path = $this->file([
            ['0001', '0001', 'A', 1, null, 12, 5, null, 'SALDO AWAL'],
            ['0001B', '0001', 'A dus', 10, null, null, 5, null, 'SALDO AWAL'],
            ['0002', '0002', 'B', 1, null, 3, 5, 'Penitip X', 'KONSINYASI'],
            ['0003', '0003', 'C', 1, null, null, 5, 'Penitip X', 'KONSINYASI'],
            ['7777', '7777', 'Tak dikenal', 1, null, 1, 5, null, 'SALDO AWAL'],
        ]);

        $service = app(OpeningStockImportService::class);
        $plan = $service->plan($path);
        $this->assertSame(1, $plan['skipped_units']);
        $this->assertCount(1, $plan['unmatched']);
        $this->assertSame(0, $service->realMovementCount());

        $stats = $service->apply($plan, '2026-01-01', $wh->id);
        $this->assertSame(12, $stats['qty']);
        $this->assertSame(24000, $stats['value']);
        $this->assertSame(3, $stats['consignment_qty']);
        $this->assertSame(1, $stats['consignors']);

        $this->assertSame(12, (int) $a->fresh()->stock);
        $this->assertSame(12, (int) $a->warehouses()->first()->pivot->stock);
        $this->assertSame(0, (int) $later->fresh()->stock);
        $this->assertSame(0, (int) $later->warehouses()->first()->pivot->stock);
        $consignor = Consignor::sole();
        $this->assertSame($consignor->id, (int) $b->fresh()->consignor_id);
        $this->assertSame($consignor->id, (int) $c->fresh()->consignor_id);
        $this->assertSame(3, (int) $b->fresh()->stock);
        $this->assertSame(0, StockMutation::where('reference_type', 'ipos_import')->count());
        $this->assertSame('2026-01-01', StockMutation::where('reference_type', 'opening_balance')->sole()->created_at->toDateString());
        $this->assertSame(3, (int) ConsignmentMovement::sole()->qty);
        $this->assertSame(0, JournalEntry::count());

        // Dijalankan ulang: hasil sama, tidak dobel.
        $service->apply($service->plan($path), '2026-01-01', $wh->id);
        $this->assertSame(12, (int) $a->fresh()->stock);
        $this->assertSame(1, ConsignmentMovement::count());
        $this->assertSame(2, StockMutation::count());
        $this->assertSame(1, Consignor::count());
    }

    public function test_command_refuses_to_overwrite_real_stock_movements_without_force(): void
    {
        $wh = Warehouse::factory()->create();
        $p = $this->product('0001', 5);
        StockMutation::create(['product_id' => $p->id, 'warehouse_id' => $wh->id, 'reference_type' => 'transaction', 'reference_id' => 1, 'mutation_type' => 'out', 'qty' => 1, 'stock_before' => 6, 'stock_after' => 5]);
        $path = $this->file([['0001', '0001', 'A', 1, null, 12, 5, null, 'SALDO AWAL']]);

        $this->artisan('inventory:opening-import', ['path' => $path])->assertSuccessful();
        $this->assertSame(5, (int) $p->fresh()->stock);
        $this->artisan('inventory:opening-import', ['path' => $path, '--apply' => true])->assertFailed();
        $this->assertSame(5, (int) $p->fresh()->stock);
    }
}
