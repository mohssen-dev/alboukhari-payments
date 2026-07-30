<?php

use App\Models\Setting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// التذكير الدوري التلقائي - يومياً في الساعة المحدّدة
try {
    $triggerHour = (int) Setting::get('trigger_hour', 9);
    $triggerMinute = (int) Setting::get('trigger_minute', 5);
} catch (\Throwable $e) {
    // قبل تشغيل المايجريشن، تستخدم القيم الافتراضية
    $triggerHour = 9;
    $triggerMinute = 5;
}

Schedule::command('reminders:dispatch')
    ->dailyAt(sprintf('%02d:%02d', $triggerHour, $triggerMinute))
    ->timezone(config('app.timezone'))
    ->onOneServer();

// Daily database backup at 03:00 — keeps last 14 backups
Schedule::command('db:backup --keep=14')
    ->dailyAt('03:00')
    ->timezone(config('app.timezone'))
    ->onOneServer();

// Weekly cleanup of old activity logs (older than 365 days)
Schedule::command('activitylog:clean')
    ->weekly()
    ->onOneServer();
