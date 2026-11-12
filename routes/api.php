<?php

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\EnsureAccountUsable;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

// Signed, short-lived links to private files (local disk only; S3 uses pre-signed URLs).
Route::get('/files/{path}', [FileController::class, 'show'])->where('path', '[A-Za-z0-9/_.-]+')->name('files.show');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:signup')->name('auth.register');
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('/super-admin/code', [AuthController::class, 'requestSuperAdminCode'])->name('auth.super-admin.code');
    Route::post('/recovery', [AuthController::class, 'requestRecovery'])->name('auth.recovery');
    Route::post('/recovery/confirm', [AuthController::class, 'confirmRecovery'])->name('auth.recovery.confirm');
    Route::post('/email-change/confirm', [ProfileController::class, 'confirmEmailChange'])->name('auth.email-change.confirm');
});

Route::middleware(['auth:sanctum', EnsureAccountUsable::class])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::get('/me', [MeController::class, 'show'])->name('me.show');
    Route::put('/me/password', [MeController::class, 'password'])->name('me.password');
    Route::patch('/me', [ProfileController::class, 'update'])->name('me.update');
    Route::post('/me/photo', [ProfileController::class, 'uploadPhoto'])->name('me.photo.store');
    Route::delete('/me/photo', [ProfileController::class, 'deletePhoto'])->name('me.photo.destroy');
    Route::post('/me/email', [ProfileController::class, 'requestEmailChange'])->name('me.email');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');

    Route::prefix('admin')->group(function () {
        // Authorization per action in UserPolicy (role + permission tier).
        Route::get('/permissions', [UserController::class, 'permissionCatalog'])->middleware('can:super-admin')->name('admin.permissions.index');
        Route::get('/users', [UserController::class, 'index'])->name('admin.users.index');
        Route::post('/users', [UserController::class, 'store'])->name('admin.users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('admin.users.show');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('admin.users.destroy');
        Route::post('/users/{user}/approve', [UserController::class, 'approve'])->name('admin.users.approve');
        Route::post('/users/{user}/reject', [UserController::class, 'reject'])->name('admin.users.reject');
        Route::post('/users/{user}/block', [UserController::class, 'block'])->name('admin.users.block');
        Route::post('/users/{user}/unblock', [UserController::class, 'unblock'])->name('admin.users.unblock');
        Route::post('/users/{user}/unlock', [UserController::class, 'unlock'])->name('admin.users.unlock');
        Route::post('/users/{user}/code', [UserController::class, 'regenerateCode'])->name('admin.users.code');
        Route::put('/users/{user}/permissions', [UserController::class, 'permissions'])->name('admin.users.permissions');
        Route::put('/users/{user}/role', [UserController::class, 'role'])->name('admin.users.role');

        Route::middleware('can:super-admin')->group(function () {
            Route::get('/settings', [SettingsController::class, 'index'])->name('admin.settings.index');
            Route::put('/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
            Route::get('/settings/history', [SettingsController::class, 'history'])->name('admin.settings.history');
        });
    });
});
