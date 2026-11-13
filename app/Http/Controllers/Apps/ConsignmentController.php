<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Consignor;
use App\Models\ConsignmentPayment;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Consignment\ConsignmentService;
use App\Services\Consignment\DocumentNumber;
use App\Services\Cooperative\FinancingSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Menu Konsinyasi: penitip, barang titipan, titip/tarik, Instruksi Pembayaran. */
class ConsignmentController extends Controller
{
    public function __construct(private readonly ConsignmentService $service) {}

    private const CASH = ['11102' => 'Rekening BSI (transfer)', '11104' => 'Kas toko (tunai)'];

    public function index()
    {
        $balances = $this->service->balances();

        return Inertia::render('Dashboard/Consignment/Index', [
            'consignors' => Consignor::with('customer:id,name,nip')->orderByDesc('is_active')->orderBy('name')->get()
                ->map(fn ($c) => $c->toArray() + ['summary' => $balances[$c->id] ?? null]),
            'employees' => Customer::whereNotNull('employee_type')->orderBy('name')->get(['id', 'name', 'nip', 'employee_type']),
            'numberCode' => DocumentNumber::code('consignment'),
        ]);
    }

    private function validateConsignor(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'phone' => ['nullable', 'string', 'max:50'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account' => ['nullable', 'string', 'max:50'],
            'account_holder' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }

    public function store(Request $request)
    {
        $c = Consignor::create($this->validateConsignor($request));

        return redirect()->route('consignments.show', $c)->with('success', "Penitip {$c->name} ditambahkan.");
    }

    public function update(Request $request, Consignor $consignor)
    {
        $consignor->update($this->validateConsignor($request));

        return back()->with('success', 'Data penitip disimpan.');
    }

    public function show(Request $request, Consignor $consignor)
    {
        $from = $request->query('from') ?: $this->service->nextPeriodFrom($consignor);
        $to = $request->query('to') ?: now()->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $date = now()->toDateString();

        return Inertia::render('Dashboard/Consignment/Show', [
            'consignor' => $consignor->load('customer:id,name,nip'),
            'summary' => $this->service->balances()[$consignor->id] ?? null,
            'products' => $consignor->products()->with('warehouses:id,name')->orderBy('title')
                ->get(['id', 'title', 'barcode', 'buy_price', 'sell_price', 'stock', 'consignor_id'])
                ->map(fn ($p) => $p->only(['id', 'title', 'barcode', 'buy_price', 'sell_price', 'stock']) + ['warehouses' => $p->warehouses->map(fn ($w) => ['id' => $w->id, 'name' => $w->name, 'stock' => (int) $w->pivot->stock])]),
            'movements' => $consignor->movements()->with(['product:id,title', 'warehouse:id,name'])->latest('date')->latest('id')->limit(100)->get(),
            'payments' => $consignor->payments()->orderByDesc('date')->orderByDesc('id')->get(['id', 'number', 'date', 'period_from', 'period_to', 'total', 'sales_total', 'status', 'paid_at', 'cash_account']),
            'period' => ['from' => $from, 'to' => $to],
            'sales' => $this->service->salesRows($consignor, $from, $to),
            'nextNumber' => DocumentNumber::next('consignment', $date),
            'today' => $date,
            'warehouses' => Warehouse::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'otherProducts' => Product::whereNull('consignor_id')->orderBy('title')->get(['id', 'title', 'barcode']),
            'cashAccounts' => self::CASH,
        ]);
    }

    public function storeProduct(Request $request, Consignor $consignor)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:100', 'unique:products,barcode'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'buy_price' => ['required', 'integer', 'min:0'],
            'sell_price' => ['required', 'integer', 'min:0', 'gte:buy_price'],
            'qty' => ['nullable', 'integer', 'min:0'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'date' => ['nullable', 'date'],
        ], ['sell_price.gte' => 'Harga jual tidak boleh lebih kecil dari harga titip.']);
        $product = DB::transaction(function () use ($data, $consignor, $request) {
            $code = $data['barcode'] ?: 'KNS'.str_pad((string) $consignor->id, 3, '0', STR_PAD_LEFT).'-'.strtoupper(substr(uniqid(), -6));
            $product = Product::create([
                'title' => $data['title'], 'barcode' => $code, 'sku' => $code, 'category_id' => $data['category_id'], 'description' => 'Barang titipan (konsinyasi)',
                'buy_price' => $data['buy_price'], 'sell_price' => $data['sell_price'], 'stock' => 0, 'tax_rate' => 0, 'consignor_id' => $consignor->id,
            ]);
            if (! empty($data['qty'])) {
                $this->service->move($consignor, $product, $data['warehouse_id'] ?? null, 'in', (int) $data['qty'], $data['date'] ?? now()->toDateString(), 'Titip awal', $request->user()?->id);
            }

            return $product;
        });

        return back()->with('success', "Barang titipan {$product->title} ditambahkan.");
    }

    /** Tandai barang yang sudah ada sebagai titipan penitip ini (atau lepaskan). */
    public function attachProduct(Request $request, Consignor $consignor)
    {
        $data = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id'], 'detach' => ['boolean']]);
        $product = Product::findOrFail($data['product_id']);
        if (! empty($data['detach'])) {
            abort_unless((int) $product->consignor_id === (int) $consignor->id, 404);
            $product->update(['consignor_id' => null]);

            return back()->with('success', "{$product->title} tidak lagi barang titipan.");
        }
        if ($product->consignor_id && (int) $product->consignor_id !== (int) $consignor->id) {
            return back()->withErrors(['product_id' => 'Barang ini sudah titipan penitip lain.']);
        }
        $product->update(['consignor_id' => $consignor->id]);

        return back()->with('success', "{$product->title} ditandai sebagai titipan. Harga beli dipakai sebagai harga titip.");
    }

    public function updatePrice(Request $request, Consignor $consignor, Product $product)
    {
        abort_unless((int) $product->consignor_id === (int) $consignor->id, 404);
        $data = $request->validate(['buy_price' => ['required', 'integer', 'min:0'], 'sell_price' => ['required', 'integer', 'min:0', 'gte:buy_price']],
            ['sell_price.gte' => 'Harga jual tidak boleh lebih kecil dari harga titip.']);
        $product->update($data);

        return back()->with('success', 'Harga disimpan. Berlaku untuk penjualan berikutnya.');
    }

    public function move(Request $request, Consignor $consignor)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'type' => ['required', Rule::in(['in', 'out'])],
            'qty' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $this->service->move($consignor, Product::findOrFail($data['product_id']), $data['warehouse_id'] ?? null, $data['type'], (int) $data['qty'], $data['date'], $data['note'] ?? null, $request->user()?->id);

        return back()->with('success', $data['type'] === 'in' ? 'Titip masuk dicatat, stok bertambah.' : 'Penarikan dicatat, stok berkurang.');
    }

    public function storePayment(Request $request, Consignor $consignor)
    {
        $data = $request->validate([
            'number' => ['nullable', 'string', 'max:60'],
            'date' => ['required', 'date'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'signer' => ['nullable', 'string', 'max:120'],
        ]);
        $p = $this->service->createPayment($consignor, $data, $request->user()?->id);

        return redirect()->route('consignments.show', $consignor)->with('success', "Instruksi pembayaran {$p->number} dibuat.");
    }

    public function nextNumber(Request $request)
    {
        $date = $request->query('date') ?: now()->toDateString();
        $type = $request->query('type') === 'kantin' ? 'kantin' : 'consignment';

        return response()->json(['number' => DocumentNumber::next($type, $date)]);
    }

    public function printPayment(ConsignmentPayment $payment)
    {
        return Inertia::render('Dashboard/Consignment/PrintPayment', [
            'payment' => $payment->load('consignor'),
            'coop' => FinancingSettings::coop(),
        ]);
    }

    public function payPayment(Request $request, ConsignmentPayment $payment)
    {
        $data = $request->validate(['paid_at' => ['required', 'date'], 'cash_account' => ['required', Rule::in(array_keys(self::CASH))]]);
        $this->service->markPaid($payment, Carbon::parse($data['paid_at'])->toDateString(), $data['cash_account']);

        return back()->with('success', "Instruksi {$payment->number} ditandai sudah dibayar.");
    }

    public function destroyPayment(ConsignmentPayment $payment)
    {
        if ($payment->status === 'paid') {
            return back()->withErrors(['payment' => 'Instruksi yang sudah dibayar tidak bisa dihapus.']);
        }
        $payment->delete();

        return back()->with('success', 'Instruksi pembayaran dihapus.');
    }
}
