<?php

use App\Http\Controllers\Api\SenderDeviceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API الهاتف المرسِل (تطبيق mobile/)
|--------------------------------------------------------------------------
| البادئة /api تُضاف تلقائياً. كل المسارات عدا /pair تتطلب
| Authorization: Bearer <device token> عبر middleware sender.device.
*/

Route::prefix('device')->name('device.')->group(function () {
    Route::post('pair', [SenderDeviceController::class, 'pair'])
        ->middleware('throttle:10,1')
        ->name('pair');

    Route::middleware('sender.device')->group(function () {
        Route::post('jobs/claim', [SenderDeviceController::class, 'claim'])->name('jobs.claim');
        Route::post('jobs/report', [SenderDeviceController::class, 'report'])->name('jobs.report');
        Route::post('heartbeat', [SenderDeviceController::class, 'heartbeat'])->name('heartbeat');
        Route::get('schedule', [SenderDeviceController::class, 'schedule'])->name('schedule');
        Route::post('unpair', [SenderDeviceController::class, 'unpair'])->name('unpair');
    });
});
