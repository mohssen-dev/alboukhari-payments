<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scheduled campaigns: a campaign saved with status='queued' and a
     * non-null scheduled_at is picked up by campaigns:dispatch-scheduled
     * (runs every minute via the Laravel scheduler) once the time arrives.
     * Recipients are built at FIRE time, not at scheduling time, so the
     * list reflects payments made in between.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
        });
    }
};
