<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pegawai = customer dengan data kepegawaian, supaya transaksi POS
        // dan simpanan menempel ke orang yang sama (kunci: NIP).
        Schema::table('customers', function (Blueprint $table) {
            $table->string('nip', 30)->nullable()->unique()->after('name');
            $table->string('employee_type', 20)->nullable()->after('nip');
            $table->string('rank')->nullable()->after('employee_type');
            $table->string('grade', 20)->nullable()->after('rank');
            $table->string('position')->nullable()->after('grade');
            $table->string('work_unit')->nullable()->after('position');
            $table->string('employment_status', 30)->nullable()->after('work_unit');
            $table->index('employee_type');
        });

        // Ejaan nama lain (dari Excel lama/penjualan) yang menunjuk ke pegawai.
        Schema::create('customer_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('alias')->unique();
            $table->timestamps();
        });

        // Pembayaran anggota per bulan: simpanan pokok/wajib/sukarela dan angsuran pinjaman.
        // amount negatif = penarikan / pengembalian.
        Schema::create('member_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->bigInteger('amount');
            $table->string('source', 20)->default('manual');
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'period_year']);
            $table->index(['period_year', 'period_month']);
        });

        // Penjualan dari Excel (sebelum POS dipakai) untuk sumber SHU.
        Schema::create('external_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->date('sale_date')->nullable();
            $table->string('buyer_name');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->timestamps();

            $table->index(['year', 'customer_id']);
            $table->index(['year', 'buyer_name']);
        });

        Schema::create('shu_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->bigInteger('total_shu')->default(0);
            $table->bigInteger('base_per_person')->default(0);
            $table->decimal('portion_savings', 5, 2)->default(70);
            $table->decimal('portion_purchases', 5, 2)->default(30);
            $table->decimal('portion_loans', 5, 2)->default(0);
            $table->json('savings_types');
            $table->string('savings_period', 20)->default('year');
            $table->string('sales_source', 20)->default('pos');
            $table->string('rounding', 20)->default('round');
            $table->json('excluded_customer_ids')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shu_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shu_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('savings_amount')->default(0);
            $table->bigInteger('purchase_amount')->default(0);
            $table->bigInteger('loan_amount')->default(0);
            $table->decimal('savings_share', 12, 10)->default(0);
            $table->decimal('purchase_share', 12, 10)->default(0);
            $table->decimal('loan_share', 12, 10)->default(0);
            $table->bigInteger('base_amount')->default(0);
            $table->decimal('shu_savings', 15, 2)->default(0);
            $table->decimal('shu_purchases', 15, 2)->default(0);
            $table->decimal('shu_loans', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->bigInteger('total_rounded')->default(0);
            $table->timestamps();

            $table->unique(['shu_period_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shu_allocations');
        Schema::dropIfExists('shu_periods');
        Schema::dropIfExists('external_sales');
        Schema::dropIfExists('member_contributions');
        Schema::dropIfExists('customer_aliases');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['employee_type']);
            $table->dropUnique(['nip']);
            $table->dropColumn(['nip', 'employee_type', 'rank', 'grade', 'position', 'work_unit', 'employment_status']);
        });
    }
};
