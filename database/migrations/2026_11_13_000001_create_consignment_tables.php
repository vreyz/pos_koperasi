<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konsinyasi (barang titipan) & invoice kantin.
 * - consignors: penitip (bisa pegawai), rekening tujuan pembayaran.
 * - products.consignor_id: barang milik penitip; harga titip = buy_price.
 * - transaction_details.consignor_id/consignment_cost: dicatat saat terjual (bagian penitip).
 * - consignment_movements: titip masuk / tarik keluar (stok berubah, tanpa jurnal).
 * - consignment_payments: Instruksi Pembayaran (nomor NN/IP-NAP/BULAN/YY) + rincian barang.
 * - kantin_invoices: invoice kantin mingguan (nomor NN/BC-KKJ/BULAN/YYYY).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('consignors')) {
            Schema::create('consignors', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('phone', 50)->nullable();
                $table->string('bank_name', 100)->nullable();
                $table->string('bank_account', 50)->nullable();
                $table->string('account_holder', 150)->nullable();
                $table->string('notes', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('products', 'consignor_id')) {
            Schema::table('products', fn (Blueprint $t) => $t->foreignId('consignor_id')->nullable()->constrained('consignors')->nullOnDelete());
        }
        if (! Schema::hasColumn('transaction_details', 'consignor_id')) {
            Schema::table('transaction_details', function (Blueprint $t) {
                $t->unsignedBigInteger('consignor_id')->nullable()->index();
                $t->bigInteger('consignment_cost')->default(0);
            });
        }
        if (! Schema::hasTable('consignment_movements')) {
            Schema::create('consignment_movements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('consignor_id')->constrained('consignors')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->string('type', 10); // in = titip, out = tarik
                $table->integer('qty');
                $table->date('date');
                $table->string('note', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('consignment_payments')) {
            Schema::create('consignment_payments', function (Blueprint $table) {
                $table->id();
                $table->string('number', 60)->unique();
                $table->unsignedSmallInteger('year');
                $table->unsignedInteger('seq');
                $table->foreignId('consignor_id')->constrained('consignors')->cascadeOnDelete();
                $table->date('date');
                $table->date('period_from');
                $table->date('period_to');
                $table->json('items');
                $table->bigInteger('total')->default(0);
                $table->bigInteger('sales_total')->default(0);
                $table->string('status', 10)->default('draft'); // draft | paid
                $table->date('paid_at')->nullable();
                $table->string('cash_account', 10)->nullable();
                $table->string('signer', 120)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('kantin_invoices')) {
            Schema::create('kantin_invoices', function (Blueprint $table) {
                $table->id();
                $table->string('number', 60)->unique();
                $table->unsignedSmallInteger('year');
                $table->unsignedInteger('seq');
                $table->date('date');
                $table->date('period_from');
                $table->date('period_to');
                $table->bigInteger('sales')->default(0);
                $table->decimal('fee_percent', 5, 2)->default(0);
                $table->json('deductions')->nullable(); // [{label, amount, account}]
                $table->bigInteger('total')->default(0);
                $table->string('payee_name', 150)->nullable();
                $table->string('payee_bank', 100)->nullable();
                $table->string('payee_account', 50)->nullable();
                $table->string('signer', 120)->nullable();
                $table->string('status', 10)->default('draft');
                $table->date('paid_at')->nullable();
                $table->string('cash_account', 10)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kantin_invoices');
        Schema::dropIfExists('consignment_payments');
        Schema::dropIfExists('consignment_movements');
        if (Schema::hasColumn('transaction_details', 'consignor_id')) {
            Schema::table('transaction_details', fn (Blueprint $t) => $t->dropColumn(['consignor_id', 'consignment_cost']));
        }
        if (Schema::hasColumn('products', 'consignor_id')) {
            Schema::table('products', function (Blueprint $t) {
                $t->dropConstrainedForeignId('consignor_id');
            });
        }
        Schema::dropIfExists('consignors');
    }
};
