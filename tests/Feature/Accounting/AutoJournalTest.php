<?php

namespace Tests\Feature\Accounting;

use App\Models\Customer;
use App\Models\Financing;
use App\Models\JournalEntry;
use App\Models\MemberContribution;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AutoJournalService;
use App\Services\Accounting\ReportService;
use App\Services\Cooperative\FinancingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoJournalTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2027-12-31 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set(AutoJournalService::FROM_KEY, '2027-01-01');
        // Jurnal otomatis tidak dibuat untuk tanggal yang belum lewat: tes berjalan "di akhir 2027".
        $this->travelTo(self::NOW);
    }

    private function employee(string $name, string $type = 'PNS'): Customer
    {
        return Customer::create(['name' => $name, 'employee_type' => $type, 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
    }

    private function bal(string $code): int
    {
        return app(ReportService::class)->accountBalance($code, '2030-12-31');
    }

    private function flush(): void
    {
        app(AutoJournalService::class)->flushDirty();
    }

    private function sale(Warehouse $wh, string $date, array $attrs): Transaction
    {
        $this->travelTo($date.' 10:00:00');
        $t = Transaction::create($attrs + [
            'cashier_id' => User::factory()->create()->id, 'warehouse_id' => $wh->id, 'invoice' => 'TRX-'.uniqid(),
            'cash' => 0, 'change' => 0, 'discount' => 0, 'payment_status' => 'paid',
        ]);
        $this->travelTo(self::NOW);

        return $t;
    }

    public function test_nothing_is_journaled_before_the_start_date(): void
    {
        $wh = Warehouse::factory()->create(['name' => 'Toko']);
        $this->sale($wh, '2026-12-30', ['grand_total' => 50000, 'payment_method' => 'cash']);
        $this->flush();
        app(AutoJournalService::class)->syncAll('2026-01-01', '2026-12-31');
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_pos_sales_become_one_daily_journal_and_kantin_is_split(): void
    {
        $toko = Warehouse::factory()->create(['name' => 'Toko Koperasi']);
        $kantin = Warehouse::factory()->create(['name' => 'Kantin Kaban']);
        $andi = $this->employee('Andi');
        $this->sale($toko, '2027-01-05', ['grand_total' => 50000, 'payment_method' => 'cash']);
        $this->sale($toko, '2027-01-05', ['grand_total' => 30000, 'payment_method' => 'qris_manual']);
        $this->sale($toko, '2027-01-05', ['grand_total' => 99000, 'payment_method' => 'qris', 'payment_status' => 'pending']); // belum dibayar
        $pg = $this->sale($toko, '2027-01-05', ['grand_total' => 25000, 'payment_method' => 'pay_later', 'payment_status' => 'unpaid', 'customer_id' => $andi->id]);
        $pg->receivable()->create(['customer_id' => $andi->id, 'invoice' => $pg->invoice, 'total' => 25000, 'paid' => 0, 'due_date' => '2027-01-31', 'status' => 'unpaid', 'source' => 'potong_gaji']);
        $this->sale($kantin, '2027-01-05', ['grand_total' => 100000, 'payment_method' => 'qris_manual']);
        $this->flush();

        $this->assertSame(1, JournalEntry::where('auto_key', "pos:2027-01-05:{$toko->id}")->count());
        $this->assertSame(-105000, $this->bal('41100'));
        $this->assertSame(50000, $this->bal('11104'));
        $this->assertSame(25000, $this->bal('11204'));
        $this->assertSame(25000, (int) JournalEntry::where('auto_key', 'like', 'pos:%')->first()->lines()->where('customer_id', $andi->id)->sum('debit'));
        // Kantin: uang muka lalu bagi hasil 10% dan hutang penjaga kantin.
        $this->assertSame(2, JournalEntry::where('auto_key', "pos:2027-01-05:{$kantin->id}")->count());
        $this->assertSame(0, $this->bal('21112'));
        $this->assertSame(-10000, $this->bal('43101'));
        $this->assertSame(-90000, $this->bal('21104'));
        $this->assertSame(130000, $this->bal('11102'));

        // Transaksi QRIS yang tadinya menunggu kini lunas: jurnal hari itu diganti, tidak dobel.
        Transaction::where('payment_status', 'pending')->first()->update(['payment_status' => 'paid']);
        $this->flush();
        $this->assertSame(1, JournalEntry::where('auto_key', "pos:2027-01-05:{$toko->id}")->count());
        $this->assertSame(-204000, $this->bal('41100'));
    }

    public function test_financing_fee_is_paid_upfront_and_installments_follow(): void
    {
        $svc = app(FinancingService::class);
        $andi = $this->employee('Andi');
        $f = Financing::create($svc->compute(['type' => 'dana', 'principal' => 10000000, 'tenor' => 10, 'admin_fee' => 300000], $andi)
            + ['type' => 'dana', 'customer_id' => $andi->id, 'status' => 'disetujui', 'number' => 'FPD-1', 'admin_fee' => 300000]);
        $svc->disburse($f, '2027-01-10', null);
        $this->flush();

        $this->assertSame(10000000, $this->bal('11301'));
        $this->assertSame(-300000, $this->bal('46101'));               // pendapatan, bukan biaya
        $this->assertSame(300000 - 10000000, $this->bal('11102'));     // transfer penuh 10 jt, formulir masuk terpisah

        $first = $f->installments()->orderBy('seq')->first();
        $svc->setPaid($first, true, 'manual', '2027-02-05');            // angsuran ditransfer langsung
        $svc->setPaid($f->installments()->where('seq', 2)->first(), true, 'potong_gaji', '2027-03-31'); // lewat rekap: tanpa jurnal kas
        $this->flush();
        $this->assertSame(10000000 - (int) $first->amount, $this->bal('11301'));
        $this->assertSame(1, JournalEntry::where('auto_key', 'like', 'inst:%')->count());

        $svc->setPaid($first->fresh(), false);                          // dibatalkan -> jurnalnya hilang
        $this->flush();
        $this->assertSame(10000000, $this->bal('11301'));
    }

    public function test_goods_financing_recognizes_margin_per_installment(): void
    {
        $svc = app(FinancingService::class);
        $budi = $this->employee('Budi');
        $f = Financing::create(['type' => 'barang', 'customer_id' => $budi->id, 'status' => 'disetujui', 'number' => 'FPB-1',
            'principal' => 6000000, 'margin_percent' => 7.5, 'margin_amount' => 450000, 'admin_fee' => 0, 'tenor' => 6, 'installment' => 1075000]);
        $svc->disburse($f, '2027-01-12', null);
        $svc->setPaid($f->installments()->orderBy('seq')->first(), true, 'potong_gaji', '2027-02-28');
        $this->flush();

        $this->assertSame(6450000, $this->bal('11303'));
        $this->assertSame(-450000 + 75000, $this->bal('11304'));
        $this->assertSame(-75000, $this->bal('46102'));
    }

    public function test_imported_deductions_create_monthly_recap_per_employee(): void
    {
        $andi = $this->employee('Andi');
        $dewi = $this->employee('Dewi', 'PPNPN');
        MemberContribution::create(['customer_id' => $andi->id, 'type' => 'wajib', 'period_year' => 2027, 'period_month' => 1, 'amount' => 100000, 'source' => 'import']);
        MemberContribution::create(['customer_id' => $dewi->id, 'type' => 'wajib', 'period_year' => 2027, 'period_month' => 1, 'amount' => 50000, 'source' => 'import']);
        $this->travelTo('2027-01-20 09:00:00'); // setoran tunai dijurnal pada tanggal uangnya diterima
        MemberContribution::create(['customer_id' => $andi->id, 'type' => 'sukarela', 'period_year' => 2027, 'period_month' => 1, 'amount' => 20000, 'source' => 'tunai']);
        $this->travelTo(self::NOW);
        $this->flush();

        $recap = JournalEntry::where('auto_key', 'rekap:2027-01')->sole();
        $this->assertSame('2027-01-31', $recap->date->toDateString());
        $this->assertSame(150000, (int) $recap->lines()->sum('debit'));
        $this->assertSame(-100000, $this->bal('31201'));
        $this->assertSame(-50000, $this->bal('31202'));
        // Setoran tunai langsung: jurnal sendiri, tidak masuk rekap.
        $this->assertSame(-20000, $this->bal('31301'));
        $this->assertSame(20000, $this->bal('11101'));

        // Impor diperbaiki: jurnal rekap diganti, tetap satu.
        MemberContribution::where('customer_id', $andi->id)->where('type', 'wajib')->update(['amount' => 150000]);
        app(AutoJournalService::class)->syncAll();
        $this->assertSame(1, JournalEntry::where('auto_key', 'rekap:2027-01')->count());
        $this->assertSame(-150000, $this->bal('31201'));
    }

    public function test_manual_entry_of_automated_event_is_blocked_after_start_date(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['accounting-access', 'accounting-create', 'accounting-manage']);
        $andi = $this->employee('Andi');
        $payload = fn ($date) => ['event' => 'PD-01', 'date' => $date, 'values' => ['customer_id' => $andi->id, 'amount' => 300000, 'cash' => '11102']];

        $this->actingAs($user)->post(route('accounting.record.store'), $payload('2027-01-05'))->assertSessionHasErrors('event');
        $this->actingAs($user)->post(route('accounting.record.store'), $payload('2026-06-05'))->assertSessionHasNoErrors();
        $this->assertSame(-300000, $this->bal('46101'));
    }

    public function test_sync_button_and_command_run(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['accounting-access', 'accounting-manage']);
        $this->actingAs($user)->post(route('accounting.sync'))->assertSessionHas('success');
        $this->artisan('accounting:sync')->assertSuccessful();
    }

    private function product(int $buyPrice): \App\Models\Product
    {
        $cat = \App\Models\Category::create(['name' => 'Umum', 'image' => 'x.png', 'description' => '-']);

        return \App\Models\Product::create(['category_id' => $cat->id, 'image' => 'x.png', 'barcode' => uniqid(), 'sku' => uniqid(), 'title' => 'Gula 1kg',
            'description' => '-', 'buy_price' => $buyPrice, 'sell_price' => $buyPrice + 2000, 'stock' => 0, 'tax_rate' => 0]);
    }

    public function test_supplier_return_initial_stock_and_cash_drawer_are_journaled(): void
    {
        $this->travelTo('2027-02-01 09:00:00');
        $product = $this->product(15000);
        \App\Models\StockMutation::create(['product_id' => $product->id, 'reference_type' => 'product_create', 'reference_id' => $product->id,
            'mutation_type' => 'in', 'qty' => 10, 'stock_before' => 0, 'stock_after' => 10]);
        $supplier = \App\Models\Supplier::create(['name' => 'Grosir Jaya']);
        $ret = \App\Models\SupplierReturn::create(['supplier_id' => $supplier->id, 'document_number' => 'RS-1', 'status' => 'draft']);
        $ret->items()->create(['product_id' => $product->id, 'qty_returned' => 2, 'unit_price' => 15000]);
        $ret->update(['status' => 'completed', 'returned_at' => now()]);
        $cashier = User::factory()->create();
        $shift = \App\Models\CashierShift::create(['user_id' => $cashier->id, 'opened_by' => $cashier->id, 'opened_at' => now(), 'status' => 'open']);
        \App\Models\ShiftCashMovement::create(['cashier_shift_id' => $shift->id, 'type' => 'out', 'amount' => 40000, 'note' => 'Setor ke bendahara', 'user_id' => $cashier->id]);
        $this->travelTo(self::NOW);
        $this->flush();

        $this->assertSame(150000 - 30000, $this->bal('11501'));   // stok awal 10 x 15rb, retur 2 x 15rb
        $this->assertSame(-150000 + 30000, $this->bal('21111'));
        $this->assertSame(-40000, $this->bal('11104'));
        $this->assertSame(40000, $this->bal('11101'));

        // Retur dibatalkan -> jurnalnya hilang.
        $ret->update(['status' => 'cancelled']);
        $this->flush();
        $this->assertSame(150000, $this->bal('11501'));
    }

    public function test_bulk_upload_and_deleted_rows_are_followed_by_sync(): void
    {
        $andi = $this->employee('Andi');
        // Upload massal (insert tanpa model) -> tetap terjurnal.
        MemberContribution::insert([['customer_id' => $andi->id, 'type' => 'wajib', 'period_year' => 2027, 'period_month' => 2, 'amount' => 100000,
            'source' => 'import', 'created_at' => now(), 'updated_at' => now()]]);
        \App\Services\Accounting\AutoJournalHooks::bulkChanged();
        $this->flush();
        $this->assertSame(-100000, $this->bal('31201'));

        // Baris dihapus lewat query (tanpa model) -> sapu bersih menghapus jurnalnya.
        MemberContribution::query()->delete();
        app(AutoJournalService::class)->syncAll();
        $this->assertSame(0, JournalEntry::where('auto_key', 'rekap:2027-02')->count());
        $this->assertSame(0, $this->bal('31201'));
    }

    public function test_rebuilt_installment_schedule_does_not_leave_old_journals(): void
    {
        $svc = app(FinancingService::class);
        $andi = $this->employee('Andi');
        $f = Financing::create(['type' => 'dana', 'customer_id' => $andi->id, 'status' => 'disetujui', 'number' => 'FPD-9', 'principal' => 2000000,
            'margin_amount' => 0, 'admin_fee' => 0, 'tenor' => 2, 'installment' => 1000000]);
        $svc->disburse($f, '2027-01-10', null);
        $svc->setPaid($f->installments()->first(), true, 'manual', '2027-02-05');
        $this->flush();
        $this->assertSame(1, JournalEntry::where('auto_key', 'like', "inst:{$f->id}:%")->count());

        $svc->buildSchedule($f->fresh()); // jadwal dibuat ulang: id cicilan lama hilang
        $this->flush();
        $this->assertSame(0, JournalEntry::where('auto_key', 'like', "inst:{$f->id}:%")->count());
        $this->assertSame(2000000, $this->bal('11301'));
    }

    public function test_future_dated_events_wait_until_their_date(): void
    {
        Setting::set(AutoJournalService::FROM_KEY, '2020-01-01');
        $svc = app(FinancingService::class);
        $andi = $this->employee('Andi');
        $f = Financing::create(['type' => 'dana', 'customer_id' => $andi->id, 'status' => 'disetujui', 'number' => 'FPD-F', 'principal' => 1000000,
            'margin_amount' => 0, 'admin_fee' => 0, 'tenor' => 1, 'installment' => 1000000]);
        $svc->disburse($f, now()->toDateString(), null);
        $svc->setPaid($f->installments()->first(), true, 'manual', now()->addMonth()->toDateString());
        $this->flush();
        $this->assertSame(0, JournalEntry::where('auto_key', 'like', 'inst:%')->count());

        $this->travel(40)->days();
        app(AutoJournalService::class)->syncAll();
        $this->assertSame(1, JournalEntry::where('auto_key', 'like', 'inst:%')->count());
    }

    public function test_supplier_payment_cash_comes_from_store_cash_and_transfer_from_bsi(): void
    {
        // Pembelian tunai dibayar dari kas toko (11104); transfer dari rekening BSI (11102).
        $supplier = \App\Models\Supplier::create(['name' => 'Supplier Contoh']);
        $payable = \App\Models\Payable::create(['supplier_id' => $supplier->id, 'document_number' => 'GR-UJI', 'total' => 300000, 'paid' => 0, 'status' => 'unpaid']);
        \App\Models\PayablePayment::create(['payable_id' => $payable->id, 'paid_at' => '2027-03-01', 'amount' => 100000, 'method' => 'cash']);
        \App\Models\PayablePayment::create(['payable_id' => $payable->id, 'paid_at' => '2027-03-02', 'amount' => 200000, 'method' => 'bank_transfer']);
        app(AutoJournalService::class)->syncAll();

        $this->assertSame(-100000, $this->bal('11104'));
        $this->assertSame(-200000, $this->bal('11102'));
        $this->assertSame(0, $this->bal('11101'));
        $this->assertSame(300000, $this->bal('21111'));
    }
}
