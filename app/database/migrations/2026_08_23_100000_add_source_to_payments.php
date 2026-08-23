<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks which payment rows the Excel importer owns.
     *
     * StudentImporter re-creates a month by DELETING every payment for that
     * student/year/month with method IN ('legacy_zero','bank') and inserting
     * the sheet value. A bank payment an office worker recorded in the app
     * matches that filter, so the next re-import silently destroyed real
     * recorded money. With an explicit source flag the importer can delete
     * only its own rows and leave manual entries alone.
     *
     * Backfill is exact: every existing imported row carries the note the
     * importer writes, and on production all 1299 bank rows are import rows.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('note')->index();
        });

        DB::table('payments')
            ->whereIn('method', ['legacy_zero', 'bank'])
            ->where('note', 'مستورد من Excel')
            ->update(['source' => 'excel_import']);
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
