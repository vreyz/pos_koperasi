<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pembiayaan Dana (pinjaman uang) dan Pembiayaan Barang (barang dicicil + bagi hasil).
        Schema::create('financings', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10); // dana | barang
            $table->string('number')->nullable();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pengajuan');
            $table->date('applied_at')->nullable();
            $table->date('disbursed_at')->nullable();
            $table->string('item_name')->nullable();
            $table->bigInteger('principal');
            $table->decimal('margin_percent', 5, 2)->default(0);
            $table->bigInteger('margin_amount')->default(0);
            $table->bigInteger('admin_fee')->default(0);
            $table->unsignedTinyInteger('tenor');
            $table->bigInteger('installment');
            $table->date('first_due_month')->nullable();
            $table->bigInteger('transferred_amount')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('phone')->nullable();
            $table->string('family_name')->nullable();
            $table->string('family_relation')->nullable();
            $table->string('family_phone')->nullable();
            $table->boolean('is_bad_debt')->default(false);
            $table->text('notes')->nullable();
            $table->string('rejection_reason')->nullable();
            foreach (['created', 'checked', 'reviewed', 'approved', 'rejected'] as $step) {
                $table->foreignId("{$step}_by")->nullable()->constrained('users')->nullOnDelete();
            }
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['customer_id', 'disbursed_at']);
        });

        Schema::create('financing_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financing_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('seq');
            $table->date('due_month');
            $table->bigInteger('amount');
            $table->date('paid_at')->nullable();
            $table->string('paid_source', 20)->nullable(); // manual | potong_gaji | pelunasan | import
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['financing_id', 'seq']);
            $table->index('due_month');
        });

        // SHU: angsuran tidak lagi dihitung; diganti jasa pembiayaan dana & barang (dari biaya/bagi hasil).
        Schema::table('shu_periods', function (Blueprint $table) {
            $table->decimal('portion_financing_dana', 5, 2)->default(0)->after('portion_loans');
            $table->decimal('portion_financing_barang', 5, 2)->default(0)->after('portion_financing_dana');
        });
        DB::table('shu_periods')->where('portion_loans', '>', 0)->update([
            'portion_financing_dana' => DB::raw('portion_loans'),
            'portion_loans' => 0,
        ]);

        Schema::table('shu_allocations', function (Blueprint $table) {
            $table->bigInteger('financing_dana_amount')->default(0)->after('loan_amount');
            $table->bigInteger('financing_barang_amount')->default(0)->after('financing_dana_amount');
            $table->decimal('financing_dana_share', 12, 10)->default(0)->after('loan_share');
            $table->decimal('financing_barang_share', 12, 10)->default(0)->after('financing_dana_share');
            $table->decimal('shu_financing_dana', 15, 2)->default(0)->after('shu_loans');
            $table->decimal('shu_financing_barang', 15, 2)->default(0)->after('shu_financing_dana');
        });
    }

    public function down(): void
    {
        Schema::table('shu_allocations', function (Blueprint $table) {
            $table->dropColumn(['financing_dana_amount', 'financing_barang_amount', 'financing_dana_share', 'financing_barang_share', 'shu_financing_dana', 'shu_financing_barang']);
        });
        Schema::table('shu_periods', function (Blueprint $table) {
            $table->dropColumn(['portion_financing_dana', 'portion_financing_barang']);
        });
        Schema::dropIfExists('financing_installments');
        Schema::dropIfExists('financings');
    }
};
