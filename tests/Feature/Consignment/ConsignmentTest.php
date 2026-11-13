<?php

namespace Tests\Feature\Consignment;

use App\Models\Category;
use App\Models\Consignor;
use App\Models\ConsignmentPayment;
use App\Models\JournalEntry;
use App\Models\KantinInvoice;
use App\Models\Product;
use App\Models\Profit;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AutoJournalService;
use App\Services\Accounting\ReportService;
use App\Services\Consignment\DocumentNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Konsinyasi: titip/tarik, penjualan barang titipan (hutang penitip + komisi), instruksi pembayaran, invoice kantin. */
class ConsignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set(AutoJournalService::FROM_KEY, '2026-01-01');
        foreach (['products-access', 'products-edit', 'payables-pay'] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $this->travelTo('2026-12-31 12:00:00');
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['products-access', 'products-edit', 'payables-pay', 'accounting-access', 'accounting-create']);

        return $user;
    }

    private function bal(string $code): int
    {
        return app(ReportService::class)->accountBalance($code, '2030-12-31');
    }

    private function consigned(Consignor $c, int $buy = 95000, int $sell = 100000): Product
    {
        $cat = Category::firstOrCreate(['name' => 'Titipan'], ['description' => 'Titipan', 'image' => '']);

        return Product::create(['title' => 'Sepatu titipan', 'barcode' => 'KNS-'.uniqid(), 'sku' => 'KNS-'.uniqid(), 'category_id' => $cat->id,
            'buy_price' => $buy, 'sell_price' => $sell, 'stock' => 0, 'tax_rate' => 0, 'consignor_id' => $c->id]);
    }

    public function test_document_numbers_follow_koperasi_format_and_restart_every_year(): void
    {
        $this->assertSame('01/BC-KKJ/X/2026', DocumentNumber::next('kantin', '2026-10-26'));
        $this->assertSame('01/IP-NAP/XI/26', DocumentNumber::next('consignment', '2026-11-03'));
        KantinInvoice::create(['number' => '40/BC-KKJ/X/2026', 'year' => 2026, 'seq' => 40, 'date' => '2026-10-26', 'period_from' => '2026-10-19', 'period_to' => '2026-10-23']);
        $this->assertSame('41/BC-KKJ/XI/2026', DocumentNumber::next('kantin', '2026-11-02'));
        $this->assertSame('01/BC-KKJ/I/2027', DocumentNumber::next('kantin', '2027-01-04'));
        $this->assertSame(30, DocumentNumber::parseSeq('30/IP-NAP/XI/26'));
    }

    public function test_deposit_and_withdrawal_change_stock_without_journal(): void
    {
        $user = $this->user();
        $wh = Warehouse::factory()->create(['name' => 'Toko']);
        $c = Consignor::create(['name' => 'Penitip A']);
        $p = $this->consigned($c);

        $this->actingAs($user)->post(route('consignments.moves.store', $c), ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'type' => 'in', 'qty' => 10, 'date' => '2026-11-01'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('consignments.moves.store', $c), ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'type' => 'out', 'qty' => 3, 'date' => '2026-11-02'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('consignments.moves.store', $c), ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'type' => 'out', 'qty' => 8, 'date' => '2026-11-03'])->assertSessionHasErrors('qty');

        $this->assertSame(7, (int) $p->fresh()->stock);
        $this->assertSame(7, (int) $p->warehouses()->first()->pivot->stock);
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_consigned_sale_becomes_payable_and_commission_then_payment_clears_it(): void
    {
        $user = $this->user();
        $wh = Warehouse::factory()->create(['name' => 'Toko']);
        $c = Consignor::create(['name' => 'Penitip A', 'bank_name' => 'BSI', 'bank_account' => '123', 'account_holder' => 'Penitip A']);
        $p = $this->consigned($c);

        $this->travelTo('2026-11-05 10:00:00');
        $t = Transaction::create(['cashier_id' => $user->id, 'warehouse_id' => $wh->id, 'invoice' => 'TRX-'.uniqid(), 'cash' => 200000, 'change' => 0,
            'discount' => 0, 'grand_total' => 200000, 'payment_method' => 'cash', 'payment_status' => 'paid']);
        $detail = $t->details()->create(['product_id' => $p->id, 'qty' => 2, 'conversion_factor' => 1, 'price' => 200000]);
        Profit::create(['transaction_id' => $t->id, 'total' => 10000]);
        $this->travelTo('2026-12-31 12:00:00');
        app(AutoJournalService::class)->syncAll('2026-01-01', '2026-12-31');

        $this->assertSame($c->id, (int) $detail->fresh()->consignor_id);
        $this->assertSame(190000, (int) $detail->fresh()->consignment_cost);
        $this->assertSame(0, $this->bal('41100'));        // bukan penjualan barang koperasi
        $this->assertSame(-190000, $this->bal('21101'));  // hutang ke penitip
        $this->assertSame(-10000, $this->bal('42101'));   // komisi koperasi
        $this->assertSame(0, $this->bal('51100'));        // tanpa HPP
        $this->assertSame(200000, $this->bal('11104'));

        $this->actingAs($user)->get(route('consignments.show', [$c, 'from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Consignment/Show')->where('sales.0.qty', 2)->where('sales.0.payment', 190000)->where('nextNumber', '01/IP-NAP/XII/26'));

        $this->actingAs($user)->post(route('consignments.payments.store', $c), ['number' => '30/IP-NAP/XII/26', 'date' => '2026-12-03', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'])->assertSessionHasNoErrors();
        $payment = ConsignmentPayment::firstOrFail();
        $this->assertSame(190000, $payment->total);
        $this->assertSame(30, $payment->seq);
        $this->actingAs($user)->get(route('consignments.payments.print', $payment))->assertOk();

        $this->actingAs($user)->post(route('consignments.payments.pay', $payment), ['paid_at' => '2026-12-04', 'cash_account' => '11102'])->assertSessionHasNoErrors();
        app(AutoJournalService::class)->flushDirty();
        $this->assertSame(0, $this->bal('21101'));
        $this->assertSame(-190000, $this->bal('11102'));
    }

    public function test_kantin_invoice_totals_and_payment_journal(): void
    {
        $user = $this->user();
        $this->actingAs($user)->post(route('accounting.kantin.invoices.store'), [
            'number' => '40/BC-KKJ/X/2026', 'date' => '2026-10-26', 'period_from' => '2026-10-19', 'period_to' => '2026-10-23',
            'sales' => 3546000, 'fee_percent' => 10, 'deductions' => [['label' => 'Potongan hutang kantin 2025', 'amount' => 450000]],
        ])->assertSessionHasNoErrors();
        $inv = KantinInvoice::firstOrFail();
        $this->assertSame(2741400, $inv->total); // 3.546.000 − 10% − 450.000
        $this->actingAs($user)->get(route('accounting.kantin.invoices.print', $inv))->assertOk();

        $this->actingAs($user)->post(route('accounting.kantin.invoices.pay', $inv), ['paid_at' => '2026-10-27', 'cash_account' => '11102'])->assertSessionHasNoErrors();
        app(AutoJournalService::class)->flushDirty();
        $this->assertSame(3191400, $this->bal('21104'));  // bagian penjaga dilunasi (debit)
        $this->assertSame(-450000, $this->bal('11205'));  // potongan mengurangi piutang ibu kantin
        $this->assertSame(-2741400, $this->bal('11102'));

        $this->actingAs($user)->get(route('accounting.kantin', ['year' => 2026]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('invoices', 1)->where('nextInvoiceNumber', '41/BC-KKJ/XII/2026'));
    }
}
