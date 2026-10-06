<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الهاتف المرسِل (تطبيق Android "مرسل البخاري").
 *
 * sender_devices               — كل هاتف مقترن بالنظام (توكن مُجزَّأ، آخر ظهور، قدراته).
 * sender_device_pairing_codes  — رموز اقتران قصيرة العمر يولّدها المدير من الويب/CLI.
 * sender_whatsapp_groups       — أسماء مجموعات واتساب المعرَّفة لكل هاتف (هدف إرسال جماعي).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // اسم يعرضه المدير: "هاتف المكتب"
            $table->string('token_hash', 64)->unique();   // sha256 للتوكن؛ التوكن نفسه لا يُخزَّن
            $table->string('model')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('android_version', 20)->nullable();
            $table->unsignedSmallInteger('sdk_int')->nullable();
            $table->string('app_version', 40)->nullable();
            $table->json('capabilities')->nullable();     // {whatsapp_package, accessibility_enabled, sms_subscription_id, ...}
            $table->json('last_heartbeat')->nullable();   // آخر نبضة كاملة كما أرسلها الهاتف
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->foreignId('paired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sender_device_pairing_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('sender_device_id')->nullable()->constrained('sender_devices')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sender_whatsapp_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_device_id')->constrained('sender_devices')->cascadeOnDelete();
            $table->string('name');                       // الاسم كما يظهر في واتساب حرفياً
            $table->timestamp('verified_at')->nullable(); // اختبار "نتيجة واحدة مطابقة" من الهاتف
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['sender_device_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_whatsapp_groups');
        Schema::dropIfExists('sender_device_pairing_codes');
        Schema::dropIfExists('sender_devices');
    }
};
