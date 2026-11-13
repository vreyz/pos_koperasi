<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Instalasi yang sempat memakai versi awal: lepas foreign key journal_entries.reversal_of_id. */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'journal_entries')
            ->where('CONSTRAINT_NAME', 'journal_entries_reversal_of_id_foreign')
            ->exists();
        if ($exists) {
            Schema::table('journal_entries', fn (Blueprint $table) => $table->dropForeign('journal_entries_reversal_of_id_foreign'));
        }
    }

    public function down(): void {}
};
