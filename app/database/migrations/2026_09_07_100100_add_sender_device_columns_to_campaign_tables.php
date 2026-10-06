<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط الحملات بالهاتف المرسِل.
 *
 * campaigns.channel           — bulkgate_sms (الافتراضي، السلوك القديم) | device_sms | device_whatsapp
 * campaigns.sender_device_id  — الهاتف الذي يرسل هذه الحملة (للقناتين device_*)
 * campaign_recipients.claimed_at / sender_device_id — من حجز الرسالة ومتى (لإعادة الرسائل العالقة)
 * campaign_recipients.sender_whatsapp_group_id      — مستلم من نوع "مجموعة واتساب" بدل رقم
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('channel', 32)->default('bulkgate_sms')->after('status')->index();
            $table->foreignId('sender_device_id')->nullable()->after('channel')->constrained('sender_devices')->nullOnDelete();
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->after('attempts');
            $table->foreignId('sender_device_id')->nullable()->after('claimed_at')->constrained('sender_devices')->nullOnDelete();
            $table->foreignId('sender_whatsapp_group_id')->nullable()->after('sender_device_id')->constrained('sender_whatsapp_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sender_whatsapp_group_id');
            $table->dropConstrainedForeignId('sender_device_id');
            $table->dropColumn('claimed_at');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sender_device_id');
            $table->dropColumn('channel');
        });
    }
};
