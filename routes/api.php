<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Attendance\AdminAttendanceController;
use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Attendance\HolidayController;
use App\Http\Controllers\Attendance\LeaveController;
use App\Http\Controllers\Attendance\WorkScheduleController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ClientErrorController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\EnsureAccountUsable;
use App\Http\Middleware\EnsureEmployee;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('health');

// Signed, short-lived links to private files (local disk only; S3 uses pre-signed URLs).
Route::get('/files/{path}', [FileController::class, 'show'])->where('path', '[A-Za-z0-9/_.-]+')->name('files.show');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('auth.register');
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

    Route::post('/client-errors', [ClientErrorController::class, 'store'])->middleware('throttle:client-errors')->name('client-errors.store');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');

    Route::get('/holidays', [HolidayController::class, 'index'])->name('holidays.index');

    // Attendance (employees only). Never behind EnsureCheckedInToday: these routes are how one checks in.
    Route::middleware(EnsureEmployee::class)->group(function () {
        Route::get('/attendance/today', [AttendanceController::class, 'today'])->name('attendance.today');
        Route::get('/attendance/history', [AttendanceController::class, 'history'])->name('attendance.history');
        Route::middleware('throttle:attendance')->group(function () {
            Route::post('/attendance/check-in/intent', [AttendanceController::class, 'intent'])->name('attendance.check-in.intent');
            Route::post('/attendance/check-in/confirm', [AttendanceController::class, 'confirm'])->name('attendance.check-in.confirm');
            Route::post('/attendance/check-out', [AttendanceController::class, 'checkOut'])->name('attendance.check-out');
        });
        Route::get('/me/work-schedule', [WorkScheduleController::class, 'mine'])->name('me.work-schedule.show');
        Route::post('/me/work-schedule', [WorkScheduleController::class, 'storeMine'])->name('me.work-schedule.store');
        Route::get('/me/leaves', [LeaveController::class, 'mine'])->name('me.leaves.index');
    });

    Route::prefix('admin')->group(function () {
        // Attendance administration (tier checked in each action).
        Route::get('/attendance/day', [AdminAttendanceController::class, 'day'])->name('admin.attendance.day');
        Route::get('/attendance/missions', [AdminAttendanceController::class, 'missions'])->name('admin.attendance.missions');
        Route::post('/attendance/{attendance}/mission/approve', [AdminAttendanceController::class, 'approveMission'])->whereNumber('attendance')->name('admin.attendance.mission.approve');
        Route::post('/attendance/{attendance}/mission/reject', [AdminAttendanceController::class, 'rejectMission'])->whereNumber('attendance')->name('admin.attendance.mission.reject');
        Route::get('/attendance/attempts', [AdminAttendanceController::class, 'attempts'])->name('admin.attendance.attempts');
        Route::get('/users/{user}/attendance', [AdminAttendanceController::class, 'userHistory'])->name('admin.users.attendance');
        Route::post('/users/{user}/check-in', [AdminAttendanceController::class, 'checkInOnBehalf'])->middleware('throttle:attendance')->name('admin.users.check-in');
        Route::get('/users/{user}/work-schedule', [WorkScheduleController::class, 'show'])->name('admin.users.work-schedule.show');
        Route::put('/users/{user}/work-schedule', [WorkScheduleController::class, 'update'])->name('admin.users.work-schedule.update');
        Route::get('/leaves', [LeaveController::class, 'index'])->name('admin.leaves.index');
        Route::post('/users/{user}/leaves', [LeaveController::class, 'store'])->name('admin.users.leaves.store');
        Route::delete('/leaves/{leave}', [LeaveController::class, 'destroy'])->whereNumber('leave')->name('admin.leaves.destroy');
        Route::post('/holidays', [HolidayController::class, 'store'])->name('admin.holidays.store');
        Route::delete('/holidays/{holiday}', [HolidayController::class, 'destroy'])->whereNumber('holiday')->name('admin.holidays.destroy');

        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->middleware('can:view-logs')->name('admin.activity-logs.index');

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
