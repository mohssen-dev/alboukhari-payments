<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuickEntryController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SendController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TemplatesController;
use App\Http\Controllers\CampaignsController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

// Public — locale switcher and auth pages
Route::get('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// Authenticated app
Route::middleware('auth')->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::view('/grid', 'grid-focus')->name('grid.focus');

    // Profile (any logged-in user)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // Read-only resources (staff + viewer + admin)
    Route::get('/reports', [ReportsController::class, 'index'])->name('reports');
    Route::get('/campaigns', [CampaignsController::class, 'index'])->name('campaigns.index');
    Route::get('/campaigns/{campaign}', [CampaignsController::class, 'show'])->name('campaigns.show');

    // Exports (all roles can export — viewers may need to print/check)
    Route::get('/exports/receipt/{payment}', [ExportController::class, 'receipt'])->name('exports.receipt');
    Route::get('/exports/statement/{student}', [ExportController::class, 'statement'])->name('exports.statement');
    Route::get('/exports/students.xlsx', [ExportController::class, 'students'])->name('exports.students');
    Route::get('/exports/payments.xlsx', [ExportController::class, 'payments'])->name('exports.payments');
    Route::get('/exports/monthly.xlsx', [ExportController::class, 'monthly'])->name('exports.monthly');

    // Write actions (staff + admin)
    Route::middleware('role:admin,staff')->group(function () {
        Route::get('/quick-entry', [QuickEntryController::class, 'index'])->name('quick-entry');

        Route::post('/campaigns/{campaign}/cancel', [CampaignsController::class, 'cancel'])->name('campaigns.cancel');

        Route::get('/send', [SendController::class, 'form'])->name('send.form');
        Route::post('/halt', [SendController::class, 'halt'])->name('halt');

        Route::get('/templates', [TemplatesController::class, 'index'])->name('templates.index');
    });

    // Admin-only
    Route::middleware('role:admin')->group(function () {
        Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
        Route::post('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/reminders/trigger', [SettingsController::class, 'triggerReminders'])->name('reminders.trigger');
        Route::post('/settings/test-bulkgate', [SettingsController::class, 'testBulkGate'])->name('settings.test_bulkgate');
        Route::post('/settings/test-whatsapp', [SettingsController::class, 'testWhatsApp'])->name('settings.test_whatsapp');

        Route::get('/import', [ImportController::class, 'form'])->name('import.form');
        Route::post('/import', [ImportController::class, 'run'])->name('import.run');

        Route::get('/users', [UsersController::class, 'index'])->name('users.index');
        Route::get('/activity', [\App\Http\Controllers\ActivityLogController::class, 'index'])->name('activity.index');
    });
});
