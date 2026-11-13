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
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\FaceIdController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\Reports\AdminReportController;
use App\Http\Controllers\Reports\ClientController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Reports\TaskController;
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

    // Web Push (VAPID public key, subscriptions of this device).
    Route::get('/push/public-key', [PushSubscriptionController::class, 'publicKey'])->name('push.public-key');
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:attendance')->name('push.subscriptions.store');
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push.subscriptions.destroy');

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

        // Tasks, clients and reports (phase 5). Reading is always allowed; every write needs today's
        // check-in on expected days (checked-in, P3-06).
        Route::get('/me/dashboard', [DashboardController::class, 'employee'])->middleware('throttle:dashboard')->name('me.dashboard');
        Route::get('/me/tasks', [TaskController::class, 'index'])->name('me.tasks.index');
        Route::get('/me/clients', [ClientController::class, 'index'])->name('me.clients.index');
        Route::get('/clients/services', [ClientController::class, 'suggestions'])->name('clients.services');
        Route::get('/me/reports/daily', [ReportController::class, 'daily'])->name('me.reports.daily');
        Route::get('/me/reports/weekly', [ReportController::class, 'weekly'])->name('me.reports.weekly');
        Route::get('/me/reports/weekly/submission', [ReportController::class, 'submissionStatus'])->name('me.reports.weekly.submission');
        Route::middleware(['checked-in', 'throttle:attendance'])->group(function () {
            Route::post('/me/tasks', [TaskController::class, 'store'])->name('me.tasks.store');
            Route::patch('/me/tasks/{task}', [TaskController::class, 'update'])->whereNumber('task')->name('me.tasks.update');
            Route::delete('/me/tasks/{task}', [TaskController::class, 'destroy'])->whereNumber('task')->name('me.tasks.destroy');
            Route::post('/me/clients', [ClientController::class, 'store'])->name('me.clients.store');
            Route::patch('/me/clients/{client}', [ClientController::class, 'update'])->whereNumber('client')->name('me.clients.update');
            Route::delete('/me/clients/{client}', [ClientController::class, 'destroy'])->whereNumber('client')->name('me.clients.destroy');
            Route::post('/me/reports/weekly/submission', [ReportController::class, 'submit'])->name('me.reports.weekly.submit');
        });

        // Face ID (WebAuthn passkeys).
        Route::get('/me/faceid', [FaceIdController::class, 'mine'])->name('me.faceid.show');
        Route::middleware('throttle:attendance')->group(function () {
            Route::post('/me/faceid/register/options', [FaceIdController::class, 'registrationOptions'])->name('me.faceid.register.options');
            Route::post('/me/faceid/register', [FaceIdController::class, 'register'])->name('me.faceid.register');
        });
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

        // Reports (tier checked in each action).
        Route::get('/reports/daily', [AdminReportController::class, 'daily'])->name('admin.reports.daily');
        Route::get('/reports/weekly', [AdminReportController::class, 'weekly'])->name('admin.reports.weekly');
        Route::get('/reports/weekly/submissions/{submission}', [AdminReportController::class, 'submission'])->whereNumber('submission')->name('admin.reports.weekly.submission');
        Route::get('/users/{user}/reports/weekly', [AdminReportController::class, 'userWeekly'])->name('admin.users.reports.weekly');

        Route::get('/faceid/credentials', [FaceIdController::class, 'index'])->name('admin.faceid.index');
        Route::post('/faceid/credentials/{credential}/approve', [FaceIdController::class, 'approve'])->whereNumber('credential')->name('admin.faceid.approve');
        Route::post('/faceid/credentials/{credential}/reject', [FaceIdController::class, 'reject'])->whereNumber('credential')->name('admin.faceid.reject');
        Route::post('/faceid/credentials/{credential}/revoke', [FaceIdController::class, 'revoke'])->whereNumber('credential')->name('admin.faceid.revoke');

        Route::get('/activity-logs', [ActivityLogController::class, 'index'])->middleware('can:view-logs')->name('admin.activity-logs.index');
        Route::get('/activity-logs/export', [ActivityLogController::class, 'export'])->middleware(['can:super-admin', 'throttle:log-export'])->name('admin.activity-logs.export');
        Route::get('/dashboard', [DashboardController::class, 'admin'])->middleware('throttle:dashboard')->name('admin.dashboard');

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

        Route::middleware('can:manage-settings')->group(function () {
            Route::get('/settings', [SettingsController::class, 'index'])->name('admin.settings.index');
            Route::put('/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
            Route::get('/settings/history', [SettingsController::class, 'history'])->name('admin.settings.history');
        });
    });
});
