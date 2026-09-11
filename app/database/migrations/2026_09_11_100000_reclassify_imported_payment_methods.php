<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Imported payments carried the wrong method.
     *
     * The school confirmed (2026-09-11) how the sheet records money: a
     * positive number is a CASH payment of that amount, and 0 means the
     * month's 30 € fee was paid by BANK transfer. The importer had it the
     * other way round — positive numbers went in as 'bank' and 0 as a
     * 'legacy_zero' marker worth nothing — so the books showed 39,862 € "by
     * bank" and hid 11,310 € of real bank income (377 months).
     *
     * Only importer-owned rows (source = excel_import) are touched; payments
     * entered in the app keep the method the office chose.
     *
     * Order matters: bank → cash must run BEFORE 0 → bank, or the freshly
     * converted bank rows would be flipped to cash as well. Every legacy_zero
     * row was verified to fall in a month whose fee is exactly 30 €, on
     * production and locally, before this ran.
     */
    public function up(): void
    {
        DB::transaction(function () {
            DB::table('payments')
                ->where('source', 'excel_import')
                ->where('method', 'bank')
                ->update(['method' => 'cash', 'updated_at' => now()]);

            DB::table('payments')
                ->where('source', 'excel_import')
                ->where('method', 'legacy_zero')
                ->update(['method' => 'bank', 'amount' => 30, 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::table('payments')
                ->where('source', 'excel_import')
                ->where('method', 'bank')
                ->update(['method' => 'legacy_zero', 'amount' => 0]);

            DB::table('payments')
                ->where('source', 'excel_import')
                ->where('method', 'cash')
                ->update(['method' => 'bank']);
        });
    }
};
