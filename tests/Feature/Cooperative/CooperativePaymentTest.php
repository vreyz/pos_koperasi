<?php

namespace Tests\Feature\Cooperative;

use App\Models\Category;
use App\Models\Customer;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\MemberContribution;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\Cooperative\PayrollReceivableService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Cara bayar koperasi: Tunai, QRIS, Transfer, Kartu Debit/Kredit, Potong Gaji (kasir & kantin). */
class CooperativePaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Warehouse $warehouse;

    private Product $product;

    private Customer $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $this->cashier = User::where('email', 'arya@gmail.com')->first();
        $this->cashier->markEmailAsVerified();
        $this->warehouse = Warehouse::create(['code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);
        $category = Category::create(['name' => 'Makanan', 'image' => 'c.jpg', 'description' => 'x']);
        $this->product = Product::create(['title' => 'Nasi Goreng', 'sku' => 'SKU-'.uniqid(), 'buy_price' => 8000, 'sell_price' => 15000, 'stock' => 100,
            'image' => 'p.jpg', 'barcode' => 'BC-'.uniqid(), 'description' => 'x', 'tax_rate' => 0, 'category_id' => $category->id]);
        $this->warehouse->products()->attach($this->product->id, ['stock' => 100]);
        $this->employee = Customer::create(['name' => 'Budi Pegawai', 'employee_type' => 'PNS', 'employment_status' => 'AKTIF', 'no_telp' => 0, 'address' => '']);
        $this->actingAs($this->cashier);
        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 0, null, $this->warehouse->id);
    }

    private function checkout(array $payload): Transaction
    {
        $this->post(route('transactions.addToCart'), ['product_id' => $this->product->id, 'sell_price' => 15000, 'qty' => 2]);
        $this->post(route('transactions.store'), $payload + ['grand_total' => 30000])->assertSessionHasNoErrors();

        return Transaction::latest('id')->first();
    }

    public function test_card_and_manual_qris_are_paid_immediately(): void
    {
        foreach (['card', 'qris_manual'] as $method) {
            $transaction = $this->checkout(['payment_gateway' => $method, 'customer_id' => $this->employee->id]);
            $this->assertSame([$method, 'paid'], [$transaction->payment_method, $transaction->payment_status]);
            $this->assertNull($transaction->payment_url);
        }
        $this->assertSame(0, Receivable::count());
    }

    public function test_potong_gaji_creates_employee_receivable_settled_by_payroll_pg(): void
    {
        $transaction = $this->checkout(['payment_gateway' => 'potong_gaji', 'customer_id' => $this->employee->id]);
        $this->assertSame(['potong_gaji', 'unpaid'], [$transaction->payment_method, $transaction->payment_status]);
        $receivable = Receivable::sole();
        $this->assertSame('potong_gaji', $receivable->source);
        $this->assertSame(now()->endOfMonth()->toDateString(), $receivable->due_date->toDateString());

        // Non-pegawai tidak boleh potong gaji.
        $umum = Customer::create(['name' => 'Pembeli Umum', 'no_telp' => 0, 'address' => '']);
        $this->post(route('transactions.addToCart'), ['product_id' => $this->product->id, 'sell_price' => 15000, 'qty' => 1]);
        $this->post(route('transactions.store'), ['payment_gateway' => 'potong_gaji', 'customer_id' => $umum->id, 'grand_total' => 15000])->assertSessionHasErrors('customer_id');

        // PG dari impor potongan gaji melunasi piutang (sebagian lalu lunas), aman dijalankan ulang.
        $service = app(PayrollReceivableService::class);
        MemberContribution::create(['customer_id' => $this->employee->id, 'type' => 'pg', 'period_year' => (int) now()->year, 'period_month' => (int) now()->month, 'amount' => 20000, 'source' => 'import']);
        $this->assertSame(1, $service->allocate());
        $this->assertSame([20000.0, 'partial'], [$receivable->fresh()->paid, $receivable->fresh()->status]);
        $this->assertSame(0, $service->allocate(), 'tidak membayar dua kali');

        MemberContribution::create(['customer_id' => $this->employee->id, 'type' => 'pg', 'period_year' => (int) now()->year, 'period_month' => (int) now()->month, 'amount' => 10000, 'source' => 'import']);
        $service->allocate();
        $this->assertSame('paid', $receivable->fresh()->status);
        $this->assertSame('paid', $transaction->fresh()->payment_status);
    }

    public function test_kantin_order_can_be_paid_with_cooperative_methods(): void
    {
        $table = DiningTable::create(['name' => 'Meja 1', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);
        $order = function () use ($table) {
            $this->post(route('dine-order.store', $table->token), ['items' => [['product_id' => $this->product->id, 'qty' => 1]], 'payment_option' => 'pay_at_counter']);
            $order = DineOrder::latest('id')->first();
            $this->actingAs($this->cashier)->post(route('dine-orders.accept', $order))->assertSessionHasNoErrors();

            return $order->fresh();
        };

        $card = $order();
        $this->post(route('dine-orders.pay', $card), ['method' => 'card'])->assertSessionHasNoErrors();
        $this->assertSame(['completed', 'paid'], [$card->fresh()->status, $card->fresh()->payment_status]);
        $this->assertSame(['card', 'paid'], [Transaction::find($card->transaction_id)->payment_method, Transaction::find($card->transaction_id)->payment_status]);

        $payroll = $order();
        $this->post(route('dine-orders.pay', $payroll), ['method' => 'potong_gaji'])->assertSessionHasErrors('customer_id');
        $this->post(route('dine-orders.pay', $payroll), ['method' => 'potong_gaji', 'customer_id' => $this->employee->id])->assertSessionHasNoErrors();
        $this->assertSame('potong_gaji', Receivable::where('transaction_id', $payroll->transaction_id)->value('source'));
        $this->assertSame($this->employee->id, Transaction::find($payroll->transaction_id)->customer_id);

        // Sudah lunas: tidak bisa dibayar lagi.
        $this->post(route('dine-orders.pay', $card), ['method' => 'cash'])->assertSessionHasErrors('method');
    }
}
