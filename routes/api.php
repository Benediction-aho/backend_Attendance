<?php

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureAccountUsable;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

Route::middleware(['auth:sanctum', EnsureAccountUsable::class])->group(function () {
    Route::prefix('admin')->group(function () {
        Route::middleware('can:super-admin')->group(function () {
            Route::get('/settings', [SettingsController::class, 'index'])->name('admin.settings.index');
            Route::put('/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
            Route::get('/settings/history', [SettingsController::class, 'history'])->name('admin.settings.history');
        });
    });
});
