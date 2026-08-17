<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reporting queries (dashboard monthly totals, reports GROUP BY, exports)
     * filter payments by period_year/period_month WITHOUT student_id, so the
     * existing (student_id, period_year, period_month) index never applies —
     * every aggregate was a full-table scan.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['period_year', 'period_month', 'method'], 'payments_period_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_period_idx');
        });
    }
};
