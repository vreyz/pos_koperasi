<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\KantinInvoice;
use App\Services\Accounting\AutoJournalService;
use App\Services\Consignment\DocumentNumber;
use App\Services\Cooperative\FinancingSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Invoice kantin mingguan (nomor NN/BC-KKJ/BULAN/YYYY): penjualan − fee koperasi − potongan = dibayar ke penjaga. */
class KantinInvoiceController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'number' => ['nullable', 'string', 'max:60'],
            'date' => ['required', 'date'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'sales' => ['required', 'integer', 'min:0'],
            'fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'deductions' => ['nullable', 'array'],
            'deductions.*.label' => ['required', 'string', 'max:150'],
            'deductions.*.amount' => ['required', 'integer', 'min:0'],
            'deductions.*.account' => ['nullable', 'string', 'max:10'],
            'payee_name' => ['nullable', 'string', 'max:150'],
            'payee_bank' => ['nullable', 'string', 'max:100'],
            'payee_account' => ['nullable', 'string', 'max:50'],
            'signer' => ['nullable', 'string', 'max:120'],
        ]);
        $number = trim($data['number'] ?? '') ?: DocumentNumber::next('kantin', $data['date']);
        if (KantinInvoice::where('number', $number)->exists()) {
            throw ValidationException::withMessages(['number' => "Nomor {$number} sudah dipakai."]);
        }
        $deductions = array_values(array_map(fn ($d) => ['label' => $d['label'], 'amount' => (int) $d['amount'], 'account' => ($d['account'] ?? null) ?: '11205'], $data['deductions'] ?? []));
        $fee = (int) round($data['sales'] * $data['fee_percent'] / 100);
        $total = $data['sales'] - $fee - array_sum(array_column($deductions, 'amount'));
        if ($total < 0) {
            throw ValidationException::withMessages(['deductions' => 'Potongan lebih besar dari bagian penjaga kantin.']);
        }
        $inv = KantinInvoice::create([
            'number' => $number, 'year' => Carbon::parse($data['date'])->year, 'seq' => DocumentNumber::parseSeq($number) ?? DocumentNumber::nextSeq('kantin', $data['date']),
            'date' => $data['date'], 'period_from' => $data['period_from'], 'period_to' => $data['period_to'], 'sales' => $data['sales'], 'fee_percent' => $data['fee_percent'],
            'deductions' => $deductions, 'total' => $total, 'payee_name' => $data['payee_name'] ?? null, 'payee_bank' => $data['payee_bank'] ?? null,
            'payee_account' => $data['payee_account'] ?? null, 'signer' => $data['signer'] ?? null, 'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', "Invoice kantin {$inv->number} dibuat.");
    }

    public function print(KantinInvoice $invoice)
    {
        return Inertia::render('Dashboard/Accounting/KantinInvoicePrint', ['invoice' => $invoice, 'fee' => $invoice->fee(), 'coop' => FinancingSettings::coop()]);
    }

    public function pay(Request $request, KantinInvoice $invoice)
    {
        $data = $request->validate(['paid_at' => ['required', 'date'], 'cash_account' => ['required', Rule::in(['11102', '11104'])]]);
        $invoice->update(['status' => 'paid', 'paid_at' => $data['paid_at'], 'cash_account' => $data['cash_account']]);
        AutoJournalService::markDirty('kantin_invoice', (string) $invoice->id);

        return back()->with('success', "Invoice {$invoice->number} ditandai sudah dibayar.");
    }

    public function destroy(KantinInvoice $invoice)
    {
        if ($invoice->status === 'paid') {
            return back()->withErrors(['invoice' => 'Invoice yang sudah dibayar tidak bisa dihapus.']);
        }
        $invoice->delete();

        return back()->with('success', 'Invoice kantin dihapus.');
    }
}
