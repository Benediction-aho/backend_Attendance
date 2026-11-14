<?php

use App\Domain\Attendance\AttendanceScope;
use App\Domain\Reports\ReportBuilder;
use App\Enums\Permission;
use App\Enums\Role;
use App\Mail\MonthlyReportMail;
use App\Mail\WeeklyReportsMail;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\DailyReport;
use App\Models\Leave;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Models\WeeklyReportSubmission;
use App\Models\WorkSchedule;
use App\Notifications\CheckinReminder;
use App\Notifications\ReportNotice;
use App\Notifications\StaffAlert;
use App\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Smalot\PdfParser\Parser;

/*
 * Owner decision 2026-11-14: admins track attendance exactly like employees from the date in the setting
 * `admin_attendance_starts_on` (never before it; empty = not at all); super admins never do; only a super
 * admin sees or manages an admin's attendance. 2026-11-16 is a Monday.
 */

beforeEach(function () {
    withoutFaceId();
    Notification::fake();
    Mail::fake();
    Storage::fake('local');
    at('2026-11-16 07:55');
});

function aaAs(object $test, User $user): void
{
    app('auth')->forgetGuards();
    $test->flushHeaders();
    Sanctum::actingAs($user);
}

function aaAlerted(User $user, string $kind): bool
{
    return Notification::sent($user, StaffAlert::class, fn (StaffAlert $n) => $n->kind === $kind)->isNotEmpty();
}

function aaSubmit(object $test, User $user)
{
    aaAs($test, $user);

    return $test->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()]);
}

describe('the setting admin_attendance_starts_on', function () {
    it('is empty by default: admins do not track attendance, employees always do, super admins never', function () {
        $scope = app(AttendanceScope::class);
        expect($scope->adminStartDate())->toBeNull()
            ->and(User::factory()->create()->tracksAttendance())->toBeTrue()
            ->and(User::factory()->admin()->create()->tracksAttendance())->toBeFalse()
            ->and(User::factory()->superAdmin()->create()->tracksAttendance())->toBeFalse();

        adminsTrackFrom('2026-11-16');
        expect(User::factory()->admin()->create()->tracksAttendance())->toBeTrue()
            ->and(User::factory()->superAdmin()->create()->tracksAttendance())->toBeFalse();
    });

    it('starts on the configured date, never before it', function () {
        adminsTrackFrom('2026-11-18');
        $admin = User::factory()->admin()->create();
        $scope = app(AttendanceScope::class);
        expect($admin->tracksAttendance())->toBeFalse()
            ->and($admin->tracksAttendance('2026-11-17'))->toBeFalse()
            ->and($admin->tracksAttendance('2026-11-18'))->toBeTrue()
            ->and($scope->rolesOn('2026-11-17'))->toBe(['employee'])
            ->and($scope->rolesOn('2026-11-18'))->toBe(['employee', 'admin']);
        at('2026-11-18 00:00');
        expect($admin->tracksAttendance())->toBeTrue();
    });

    it('never rewrites the past when it is changed later (moved back, or switched off)', function () {
        adminsTrackFrom('2026-11-16');
        at('2026-11-19 10:00');
        adminsTrackFrom('2026-11-01');
        $scope = app(AttendanceScope::class);
        expect($scope->adminsTrackOn('2026-11-10'))->toBeFalse()
            ->and($scope->adminsTrackOn('2026-11-15'))->toBeFalse()
            ->and($scope->adminsTrackOn('2026-11-17'))->toBeTrue()
            ->and($scope->adminsTrackOn('2026-11-19'))->toBeTrue();
        at('2026-11-23 10:00');
        adminsTrackFrom(null);
        expect($scope->adminsTrackOn('2026-11-20'))->toBeTrue()
            ->and($scope->adminsTrackOn('2026-11-23'))->toBeFalse()
            ->and($scope->adminsTrackOn('2026-11-24'))->toBeFalse();
    });

    it('is seen and changed by super admins only, with history, and can be emptied', function () {
        aaAs($this, User::factory()->superAdmin()->create());
        $this->getJson('/api/admin/settings')->assertOk()->assertJsonFragment([
            'key' => 'admin_attendance_starts_on', 'type' => 'date', 'nullable' => true, 'value' => null, 'default' => null,
            'section' => 'attendance', 'super_admin_only' => true,
        ]);
        $this->putJson('/api/admin/settings', ['values' => ['admin_attendance_starts_on' => '2026-11-18']])->assertOk()
            ->assertJsonFragment(['key' => 'admin_attendance_starts_on', 'value' => '2026-11-18']);
        expect(app(AttendanceScope::class)->adminStartDate())->toBe('2026-11-18');
        $this->getJson('/api/admin/settings/history?key=admin_attendance_starts_on')->assertOk()
            ->assertJsonPath('data.0.old_value', null)->assertJsonPath('data.0.new_value', '2026-11-18');

        $this->putJson('/api/admin/settings', ['values' => ['admin_attendance_starts_on' => null]])->assertOk()
            ->assertJsonFragment(['key' => 'admin_attendance_starts_on', 'value' => null]);
        expect(app(AttendanceScope::class)->adminStartDate())->toBeNull();
        $this->getJson('/api/admin/settings/history?key=admin_attendance_starts_on')->assertOk()
            ->assertJsonPath('data.0.old_value', '2026-11-18')->assertJsonPath('data.0.new_value', null);

        $this->putJson('/api/admin/settings', ['values' => ['admin_attendance_starts_on' => '18/11/2026']])->assertUnprocessable();
        $this->putJson('/api/admin/settings', ['values' => ['admin_attendance_starts_on' => 'tomorrow']])->assertUnprocessable();
        expect(ActivityLog::query()->where('event', 'settings.updated')->count())->toBe(2);
    });

    it('is hidden from and refused to an admin holding the attendance settings section', function () {
        aaAs($this, User::factory()->admin(Permission::ManageSettingsAttendance)->create());
        $keys = collect($this->getJson('/api/admin/settings')->assertOk()->json('data'))->pluck('key');
        expect($keys)->toContain('late_after')->not->toContain('admin_attendance_starts_on');
        $this->putJson('/api/admin/settings', ['values' => ['admin_attendance_starts_on' => '2026-11-16']])->assertForbidden();
        expect(app(AttendanceScope::class)->adminStartDate())->toBeNull();
        $this->getJson('/api/admin/settings/history?key=admin_attendance_starts_on')->assertOk()->assertJsonCount(0, 'data');
    });

    it('is stamped by the migration with the go-live date in Africa/Accra, once, and removed on rollback', function () {
        at('2026-11-14 23:30');
        $migration = require database_path('migrations/2026_11_23_100000_seed_admin_attendance_start.php');
        $migration->up();
        $migration->up();
        $scope = app(AttendanceScope::class);
        expect(DB::table('settings')->where('key', 'admin_attendance_starts_on')->count())->toBe(1)
            ->and(DB::table('setting_history')->where('key', 'admin_attendance_starts_on')->count())->toBe(1)
            ->and($scope->adminStartDate())->toBe('2026-11-14')
            ->and($scope->adminsTrackOn('2026-11-13'))->toBeFalse()
            ->and($scope->adminsTrackOn('2026-11-14'))->toBeTrue()
            ->and($scope->adminsTrackOn('2026-11-16'))->toBeTrue();

        $migration->down();
        expect(DB::table('settings')->where('key', 'admin_attendance_starts_on')->exists())->toBeFalse()
            ->and(DB::table('setting_history')->where('key', 'admin_attendance_starts_on')->exists())->toBeFalse()
            ->and($scope->adminStartDate())->toBeNull();
    });

    it('is left untouched by the migration when a value already exists', function () {
        adminsTrackFrom('2026-12-01');
        at('2026-11-20 10:00');
        (require database_path('migrations/2026_11_23_100000_seed_admin_attendance_start.php'))->up();
        expect(app(AttendanceScope::class)->adminStartDate())->toBe('2026-12-01');
    });
});

describe('an admin does what an employee does', function () {
    beforeEach(fn () => adminsTrackFrom('2026-11-16'));

    it('is told by /me whether the account tracks attendance', function () {
        aaAs($this, User::factory()->admin()->create());
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.tracks_attendance', true)->assertJsonPath('data.attendance_starts_on', '2026-11-16');
        aaAs($this, User::factory()->create());
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.tracks_attendance', true)->assertJsonPath('data.attendance_starts_on', null);
        aaAs($this, User::factory()->superAdmin()->create());
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.tracks_attendance', false)->assertJsonPath('data.attendance_starts_on', null);
    });

    it('checks in once a day under the same rules (perimeter, lateness, single check-in, early check-out reason)', function () {
        $admin = User::factory()->admin()->create();
        aaAs($this, $admin);
        $this->getJson('/api/attendance/today')->assertOk()->assertJsonPath('expected', true)->assertJsonPath('checked_in', false)
            ->assertJsonPath('available_check_ins', ['gps', 'mission']);
        $this->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => 5.61, 'longitude' => -0.21, 'accuracy' => 10])
            ->assertUnprocessable()->assertJsonPath('code', 'out_of_perimeter');
        $this->postJson('/api/attendance/check-in/intent', ['type' => 'remote'])->assertUnprocessable()->assertJsonPath('code', 'not_remote_day');

        at('2026-11-16 08:10');
        gpsCheckIn($this, $admin)->assertCreated()->assertJsonPath('data.is_late', true)->assertJsonPath('data.check_in_type', 'gps');
        gpsCheckIn($this, $admin)->assertStatus(409)->assertJsonPath('code', 'already_checked_in');
        expect(Attendance::query()->where('user_id', $admin->id)->count())->toBe(1);

        at('2026-11-16 15:00');
        $this->postJson('/api/attendance/check-out')->assertUnprocessable()->assertJsonPath('code', 'reason_required');
        $this->postJson('/api/attendance/check-out', ['reason' => 'Bank appointment'])->assertOk()->assertJsonPath('data.early_leave', true);
        gpsCheckIn($this, $admin)->assertStatus(409);
        $this->getJson('/api/attendance/history')->assertOk()->assertJsonPath('meta.total', 1);
    });

    it('is checked out automatically at midnight like an employee', function () {
        $admin = User::factory()->admin()->create();
        gpsCheckIn($this, $admin)->assertCreated();
        at('2026-11-17 00:00');
        $this->artisan('attendance:auto-checkout')->assertSuccessful();
        expect(Attendance::query()->where('user_id', $admin->id)->value('check_out_type'))->toBe(Attendance::CHECKOUT_AUTO);
    });

    it('needs today\'s check-in for its own tasks, clients and weekly report, but never for the admin pages', function () {
        $admin = User::factory()->admin(Permission::ViewLateness, Permission::CreateUsers, Permission::ViewReports)->create();
        aaAs($this, $admin);
        $this->getJson('/api/me/tasks')->assertOk();
        $this->postJson('/api/me/tasks', ['title' => 'Call the bank'])->assertForbidden()->assertJsonPath('code', 'checkin_required');
        $this->postJson('/api/me/clients', ['name' => 'Kofi', 'service' => 'Visa', 'quantity' => 1])->assertForbidden()->assertJsonPath('code', 'checkin_required');
        $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->assertForbidden()->assertJsonPath('code', 'checkin_required');

        // Decision 4: the admin functions stay available before the check-in.
        $this->getJson('/api/admin/dashboard')->assertOk();
        $this->getJson('/api/admin/users')->assertOk();
        $this->getJson('/api/admin/attendance/day')->assertOk();
        $this->getJson('/api/admin/reports/daily')->assertOk();

        gpsCheckIn($this, $admin)->assertCreated();
        $this->postJson('/api/me/tasks', ['title' => 'Call the bank', 'done' => true])->assertCreated();
        $this->postJson('/api/me/clients', ['name' => 'Kofi', 'service' => 'Visa', 'quantity' => 2])->assertCreated();
        $this->getJson('/api/me/reports/daily')->assertOk()->assertJsonPath('data.tasks_done', 1)->assertJsonPath('data.clients_count', 1);
        $this->getJson('/api/me/dashboard')->assertOk()->assertJsonPath('data.today.checked_in', true);
    });

    it('selects its working week once, and follows the employee rule without one', function () {
        $admin = User::factory()->admin()->create();
        $super = User::factory()->superAdmin()->create();
        $manager = User::factory()->admin(Permission::ManageWorkSchedules)->create();
        aaAs($this, $admin);
        // No working week yet: expected on every working day, like an employee.
        $this->getJson('/api/attendance/today')->assertJsonPath('day_status', 'working')->assertJsonPath('expected', true);
        $this->postJson('/api/me/work-schedule', ['days_off' => [3], 'remote_days' => [5]])->assertCreated()->assertJsonPath('upcoming.0.days_off', [3]);
        $this->postJson('/api/me/work-schedule', ['days_off' => [2], 'remote_days' => []])->assertStatus(409)->assertJsonPath('code', 'schedule_locked');
        // The alert about an admin's working week goes to super admins only.
        expect(aaAlerted($super, 'schedule_selected'))->toBeTrue()->and(aaAlerted($manager, 'schedule_selected'))->toBeFalse();
        at('2026-11-18 09:00');
        $this->getJson('/api/attendance/today')->assertJsonPath('day_status', 'day_off')->assertJsonPath('expected', false);
    });

    it('has its first phone approved automatically and later phones approved by a super admin only', function () {
        configureWebauthn();
        app(SettingsService::class)->set(['faceid_required_for_checkin' => true]);
        $admin = User::factory()->admin(Permission::ApproveFaceIdDevices)->create();
        $approver = User::factory()->admin(Permission::ApproveFaceIdDevices)->create();
        $super = User::factory()->superAdmin()->create();

        $token = deviceToken($admin);
        $phone = registerFaceId($this, $token);
        expect(WebauthnCredential::query()->where('user_id', $admin->id)->value('status'))->toBe('approved');
        faceIdCheckIn($this, $token, $phone)->assertCreated()->assertJsonPath('data.faceid_verified', true);

        aaAs($this, $admin);
        app('auth')->forgetGuards();
        $this->flushHeaders();
        registerFaceId($this, deviceToken($admin, 'device-test-0000000002'));
        $pending = WebauthnCredential::query()->where('user_id', $admin->id)->where('status', 'pending')->sole();
        expect(aaAlerted($super, 'faceid_device_request'))->toBeTrue()
            ->and(aaAlerted($approver, 'faceid_device_request'))->toBeFalse()
            ->and(aaAlerted($admin, 'faceid_device_request'))->toBeFalse();

        // Another admin holding the tier neither sees nor reviews it; the owner cannot approve it either.
        aaAs($this, $approver);
        $this->getJson('/api/admin/faceid/credentials')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.pending.faceid_devices', 0);
        $this->postJson("/api/admin/faceid/credentials/{$pending->id}/approve")->assertForbidden();
        $this->postJson("/api/admin/faceid/credentials/{$pending->id}/reject")->assertForbidden();
        $this->postJson("/api/admin/faceid/credentials/{$pending->id}/revoke")->assertForbidden();
        aaAs($this, $admin);
        $this->getJson('/api/admin/faceid/credentials')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson("/api/admin/faceid/credentials/{$pending->id}/approve")->assertForbidden();
        expect($pending->refresh()->status)->toBe('pending');

        aaAs($this, $super);
        $this->getJson('/api/admin/faceid/credentials')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.user.role', 'admin');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.pending.faceid_devices', 1);
        $this->postJson("/api/admin/faceid/credentials/{$pending->id}/approve")->assertOk();
        expect($pending->refresh()->status)->toBe('approved');
    });
});

describe('who is refused the employee features', function () {
    it('refuses admins while the setting is empty or before the start date, and super admins always', function () {
        $admin = User::factory()->admin(...Permission::cases())->create();
        $super = User::factory()->superAdmin()->create();
        $routes = [['GET', '/api/attendance/today'], ['GET', '/api/attendance/history'], ['GET', '/api/me/work-schedule'], ['GET', '/api/me/tasks'],
            ['GET', '/api/me/clients'], ['GET', '/api/me/dashboard'], ['GET', '/api/me/reports/weekly'], ['GET', '/api/me/faceid'], ['GET', '/api/me/leaves'],
            ['POST', '/api/attendance/check-in/intent'], ['POST', '/api/attendance/check-out'], ['POST', '/api/me/tasks'], ['POST', '/api/me/work-schedule']];

        $refused = function (User $user) use ($routes) {
            aaAs($this, $user);
            foreach ($routes as [$method, $uri]) {
                $this->json($method, $uri)->assertForbidden()->assertJsonPath('code', 'employees_only');
            }
        };
        $refused($admin);          // setting empty
        $refused($super);
        adminsTrackFrom('2026-11-17');
        $refused($admin);          // starts tomorrow
        at('2026-11-17 07:00');
        $refused($super);          // never
        aaAs($this, $admin);
        $this->getJson('/api/attendance/today')->assertOk();
        expect(Attendance::query()->count())->toBe(0);
    });

    it('lets an employee promoted to admin keep tracking, and stops an admin promoted to super admin', function () {
        adminsTrackFrom('2026-11-16');
        $person = User::factory()->create();
        gpsCheckIn($this, $person)->assertCreated();
        WorkSchedule::query()->create(['user_id' => $person->id, 'effective_from' => '2026-11-01', 'days_off' => [], 'remote_days' => [], 'set_by' => $person->id, 'set_by_employee' => true]);

        $person->forceFill(['role' => Role::Admin])->save();
        aaAs($this, $person->refresh());
        $this->getJson('/api/attendance/today')->assertOk()->assertJsonPath('checked_in', true);
        $this->getJson('/api/attendance/history')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/me/work-schedule', ['days_off' => [2], 'remote_days' => []])->assertStatus(409);

        $person->forceFill(['role' => Role::SuperAdmin])->save();
        aaAs($this, $person->refresh());
        $this->getJson('/api/attendance/today')->assertForbidden()->assertJsonPath('code', 'employees_only');
        expect($person->tracksAttendance())->toBeFalse();
        at('2026-11-17 08:05');
        $this->artisan('attendance:checkin-reminder')->assertSuccessful();
        Notification::assertNotSentTo($person, CheckinReminder::class);
    });
});

describe('only a super admin manages or validates for an admin', function () {
    beforeEach(function () {
        adminsTrackFrom('2026-11-16');
        $this->super = User::factory()->superAdmin()->create();
        $this->target = User::factory()->admin()->create(['first_name' => 'Tara', 'last_name' => 'Target']);
        $this->employee = User::factory()->create(['first_name' => 'Emma', 'last_name' => 'Employee']);
        // An admin holding every tier: still employees only, never another admin, never themselves.
        $this->admin = User::factory()->admin(...Permission::cases())->create(['first_name' => 'Adam', 'last_name' => 'Admin']);
    });

    it('validates a mission check-in', function () {
        foreach ([$this->target, $this->admin, $this->employee] as $u) {
            aaAs($this, $u);
            $intent = $this->postJson('/api/attendance/check-in/intent', ['type' => 'mission', 'reason' => 'Client visit'])->assertCreated();
            $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $intent->json('intent_id')])->assertCreated();
        }
        $mission = fn (User $u) => Attendance::query()->where('user_id', $u->id)->sole();
        // Alerts: about an employee to the tier holders, about an admin to super admins only.
        expect(Notification::sent($this->super, StaffAlert::class, fn (StaffAlert $n) => $n->kind === 'mission_checkin')->count())->toBe(3)
            ->and(Notification::sent($this->admin, StaffAlert::class, fn (StaffAlert $n) => $n->kind === 'mission_checkin')->count())->toBe(1);

        aaAs($this, $this->admin);
        $this->getJson('/api/admin/attendance/missions')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.user.id', $this->employee->id);
        $this->getJson('/api/admin/dashboard')->assertJsonPath('data.pending.missions', 1)->assertJsonPath('data.today.missions_pending', 1);
        $this->postJson("/api/admin/attendance/{$mission($this->target)->id}/mission/approve")->assertForbidden();
        $this->postJson("/api/admin/attendance/{$mission($this->target)->id}/mission/reject")->assertForbidden();
        $this->postJson("/api/admin/attendance/{$mission($this->admin)->id}/mission/approve")->assertForbidden();
        $this->postJson("/api/admin/attendance/{$mission($this->employee)->id}/mission/approve")->assertOk();
        expect($mission($this->target)->mission_status)->toBe('pending')->and($mission($this->admin)->mission_status)->toBe('pending');

        aaAs($this, $this->super);
        $this->getJson('/api/admin/attendance/missions')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/admin/dashboard')->assertJsonPath('data.pending.missions', 2);
        $this->postJson("/api/admin/attendance/{$mission($this->target)->id}/mission/approve")->assertOk()->assertJsonPath('data.mission_status', 'approved');
        $this->postJson("/api/admin/attendance/{$mission($this->admin)->id}/mission/reject")->assertOk();
    });

    it('checks in on behalf', function () {
        aaAs($this, $this->admin);
        $this->postJson("/api/admin/users/{$this->target->id}/check-in", ['reason' => 'Phone broken'])->assertForbidden();
        $this->postJson("/api/admin/users/{$this->admin->id}/check-in", ['reason' => 'Phone broken'])->assertForbidden();
        $this->postJson("/api/admin/users/{$this->super->id}/check-in", ['reason' => 'Phone broken'])->assertForbidden();
        expect(Attendance::query()->count())->toBe(0);
        $this->postJson("/api/admin/users/{$this->employee->id}/check-in", ['reason' => 'Phone broken'])->assertCreated();

        aaAs($this, $this->super);
        $this->postJson("/api/admin/users/{$this->target->id}/check-in", ['reason' => 'Phone broken'])->assertCreated()->assertJsonPath('data.check_in_type', 'admin_on_behalf');
        $this->postJson('/api/admin/users/'.User::factory()->superAdmin()->create()->id.'/check-in', ['reason' => 'Phone broken'])->assertForbidden();
        $this->postJson("/api/admin/users/{$this->super->id}/check-in", ['reason' => 'Phone broken'])->assertForbidden();
        expect(Attendance::query()->where('user_id', $this->target->id)->value('checked_in_by'))->toBe($this->super->id);

        // Admins do not track attendance (setting emptied): nobody checks them in.
        at('2026-11-17 09:00');
        adminsTrackFrom(null);
        $this->postJson("/api/admin/users/{$this->target->id}/check-in", ['reason' => 'Phone broken'])->assertUnprocessable()->assertJsonPath('code', 'attendance_not_tracked');
    });

    it('declares and removes leave', function () {
        $payload = ['start_date' => '2026-11-18', 'end_date' => '2026-11-19'];
        aaAs($this, $this->admin);
        $this->postJson("/api/admin/users/{$this->target->id}/leaves", $payload)->assertForbidden();
        $this->postJson("/api/admin/users/{$this->admin->id}/leaves", $payload)->assertForbidden();
        $this->postJson("/api/admin/users/{$this->employee->id}/leaves", $payload)->assertCreated();
        expect(Leave::query()->count())->toBe(1);

        aaAs($this, $this->super);
        $id = $this->postJson("/api/admin/users/{$this->target->id}/leaves", $payload)->assertCreated()->json('data.id');
        $this->postJson("/api/admin/users/{$this->super->id}/leaves", $payload)->assertForbidden();
        $this->getJson('/api/admin/leaves')->assertOk()->assertJsonPath('meta.total', 2);

        aaAs($this, $this->admin);
        $this->getJson('/api/admin/leaves')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.user_id', $this->employee->id);
        $this->getJson("/api/admin/leaves?user_id={$this->target->id}")->assertOk()->assertJsonPath('meta.total', 0);
        $this->deleteJson("/api/admin/leaves/{$id}")->assertForbidden();

        // The admin on leave sees it, is not expected, and cannot remove it through the admin endpoint.
        $own = User::factory()->admin(Permission::ManageLeaves)->create();
        aaAs($this, $this->super);
        $ownLeave = $this->postJson("/api/admin/users/{$own->id}/leaves", $payload)->assertCreated()->json('data.id');
        aaAs($this, $own);
        $this->getJson('/api/me/leaves')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/admin/leaves/{$ownLeave}")->assertForbidden();
        at('2026-11-18 09:00');
        $this->getJson('/api/attendance/today')->assertJsonPath('day_status', 'leave')->assertJsonPath('expected', false);

        aaAs($this, $this->super);
        $this->deleteJson("/api/admin/leaves/{$id}")->assertNoContent();
    });

    it('sees and edits the working week', function () {
        $body = ['days_off' => [1], 'remote_days' => [2]];
        aaAs($this, $this->admin);
        $this->getJson("/api/admin/users/{$this->target->id}/work-schedule")->assertForbidden();
        $this->putJson("/api/admin/users/{$this->target->id}/work-schedule", $body)->assertForbidden();
        $this->getJson("/api/admin/users/{$this->admin->id}/work-schedule")->assertForbidden();
        $this->putJson("/api/admin/users/{$this->admin->id}/work-schedule", $body)->assertForbidden();
        $this->putJson("/api/admin/users/{$this->employee->id}/work-schedule", $body)->assertOk();
        expect(WorkSchedule::query()->where('user_id', '!=', $this->employee->id)->exists())->toBeFalse();

        aaAs($this, $this->super);
        $this->putJson("/api/admin/users/{$this->target->id}/work-schedule", $body)->assertOk()->assertJsonPath('current.days_off', [1]);
        $this->getJson("/api/admin/users/{$this->target->id}/work-schedule")->assertOk();
        $this->putJson("/api/admin/users/{$this->super->id}/work-schedule", $body)->assertForbidden();
    });

    it('sees attendance rows, refused attempts and early check-outs', function () {
        at('2026-11-16 08:30');
        gpsCheckIn($this, $this->target)->assertCreated();
        gpsCheckIn($this, $this->employee)->assertCreated();
        aaAs($this, $this->target);
        at('2026-11-16 15:00');
        $this->postJson('/api/attendance/check-out', ['reason' => 'Doctor'])->assertOk();
        aaAs($this, $this->admin);
        $this->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => 5.61, 'longitude' => -0.21, 'accuracy' => 10])->assertUnprocessable();

        // Early check-out of an admin: super admins only.
        expect(aaAlerted($this->super, 'early_checkout'))->toBeTrue()->and(aaAlerted($this->admin, 'early_checkout'))->toBeFalse();

        $day = collect($this->getJson('/api/admin/attendance/day')->assertOk()->json('data'));
        expect($day->pluck('user.id')->all())->toBe([$this->employee->id]);
        $this->getJson("/api/admin/users/{$this->target->id}/attendance")->assertForbidden();
        $this->getJson("/api/admin/users/{$this->admin->id}/attendance")->assertForbidden();
        $this->getJson("/api/admin/users/{$this->employee->id}/attendance")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/attendance/attempts')->assertOk()->assertJsonPath('meta.total', 0);

        aaAs($this, $this->super);
        $day = collect($this->getJson('/api/admin/attendance/day')->assertOk()->json('data'))->keyBy('user.id');
        expect($day->keys()->sort()->values()->all())->toBe(collect([$this->target->id, $this->employee->id, $this->admin->id])->sort()->values()->all())
            ->and($day[$this->target->id]['user']['role'])->toBe('admin')
            ->and($day[$this->target->id]['expected'])->toBeTrue()
            ->and($day[$this->target->id]['attendance']['is_late'])->toBeTrue()
            ->and($day[$this->admin->id]['attendance'])->toBeNull();
        $this->getJson("/api/admin/users/{$this->target->id}/attendance")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/admin/users/{$this->super->id}/attendance")->assertForbidden();
        $this->getJson('/api/admin/attendance/attempts')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.user_id', $this->admin->id);
        // A day before the go-live lists no admin, even for a super admin.
        expect(collect($this->getJson('/api/admin/attendance/day?date=2026-11-13')->json('data'))->pluck('user.id')->all())->toBe([$this->employee->id]);
    });

    it('sees daily and weekly reports', function () {
        at('2026-11-20 16:20');
        foreach ([$this->target, $this->employee] as $u) {
            attend($u, '2026-11-20');
            aaSubmit($this, $u)->assertCreated();
        }
        $ofTarget = WeeklyReportSubmission::query()->where('user_id', $this->target->id)->value('id');
        $ofEmployee = WeeklyReportSubmission::query()->where('user_id', $this->employee->id)->value('id');

        aaAs($this, $this->admin);
        $weekly = $this->getJson('/api/admin/reports/weekly')->assertOk();
        expect($weekly->json('expected_count'))->toBe(1)->and($weekly->json('received_count'))->toBe(1)->and($weekly->json('missing'))->toBe([])
            ->and(collect($weekly->json('data'))->pluck('user.id')->all())->toBe([$this->employee->id]);
        $this->getJson("/api/admin/reports/weekly/submissions/{$ofTarget}")->assertNotFound();
        $this->getJson("/api/admin/reports/weekly/submissions/{$ofEmployee}")->assertOk();
        $this->getJson("/api/admin/users/{$this->target->id}/reports/weekly")->assertNotFound();
        $this->getJson("/api/admin/users/{$this->admin->id}/reports/weekly")->assertNotFound();
        $this->getJson("/api/admin/users/{$this->employee->id}/reports/weekly")->assertOk();
        expect(collect($this->getJson('/api/admin/reports/daily')->assertOk()->json('data'))->pluck('user.id')->all())->toBe([$this->employee->id]);
        $this->getJson("/api/admin/reports/daily?user_id={$this->target->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/admin/reports/daily?user_id={$this->admin->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/dashboard')->assertJsonPath('data.week.weekly_reports_expected', 1)->assertJsonPath('data.week.weekly_reports_received', 1);

        aaAs($this, $this->super);
        $weekly = $this->getJson('/api/admin/reports/weekly')->assertOk();
        expect($weekly->json('expected_count'))->toBe(3)->and($weekly->json('received_count'))->toBe(2)
            ->and(collect($weekly->json('missing'))->pluck('id')->all())->toBe([$this->admin->id])
            ->and(collect($weekly->json('data'))->pluck('user.id')->sort()->values()->all())
            ->toBe(collect([$this->target->id, $this->employee->id, $this->admin->id])->sort()->values()->all());
        $this->getJson("/api/admin/reports/weekly/submissions/{$ofTarget}")->assertOk()->assertJsonPath('data.user.role', 'admin');
        $this->getJson("/api/admin/users/{$this->target->id}/reports/weekly")->assertOk();
        expect(collect($this->getJson('/api/admin/reports/daily')->assertOk()->json('data'))->pluck('user.id'))->toHaveCount(3);
        $this->getJson("/api/admin/reports/daily?user_id={$this->target->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/dashboard')->assertJsonPath('data.week.weekly_reports_expected', 3)->assertJsonPath('data.week.weekly_reports_received', 2);
    });

    it('hides attendance log entries about admins from an admin allowed to read the logs', function () {
        gpsCheckIn($this, $this->target)->assertCreated();
        gpsCheckIn($this, $this->employee)->assertCreated();
        $events = fn () => collect($this->getJson('/api/admin/activity-logs?event=attendance.check_in')->assertOk()->json('data'))->pluck('user.id')->all();

        aaAs($this, $this->admin);
        expect($events())->toBe([$this->employee->id]);
        // Other entries about an admin (sign-ins, account changes) stay visible as before.
        ActivityLog::query()->create(['type' => 'security', 'level' => 'info', 'event' => 'auth.login', 'description' => 'Signed in', 'user_id' => $this->target->id]);
        $this->getJson("/api/admin/activity-logs?user_id={$this->target->id}")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.event', 'auth.login');

        aaAs($this, $this->super);
        expect($events())->toHaveCount(2);
    });
});

describe('totals and lists', function () {
    beforeEach(function () {
        $this->super = User::factory()->superAdmin()->create();
        $this->viewer = User::factory()->admin(...Permission::cases())->create();
        $this->other = User::factory()->admin()->create();
        $this->employee = User::factory()->create();
    });

    it('cover employees only for an admin and employees + admins for a super admin; super admins are never expected', function () {
        adminsTrackFrom('2026-11-16');
        at('2026-11-16 08:30');
        gpsCheckIn($this, $this->employee)->assertCreated();
        gpsCheckIn($this, $this->other)->assertCreated();
        Client::query()->create(['user_id' => $this->other->id, 'work_date' => '2026-11-16', 'name' => 'C', 'service' => 'Visa', 'service_key' => 'visa', 'quantity' => 3]);
        Client::query()->create(['user_id' => $this->employee->id, 'work_date' => '2026-11-16', 'name' => 'C', 'service' => 'Permit', 'service_key' => 'permit', 'quantity' => 1]);

        aaAs($this, $this->viewer);
        $d = $this->getJson('/api/admin/dashboard')->assertOk()->json('data');
        expect($d['today'])->toMatchArray(['expected' => 1, 'checked_in' => 1, 'missing' => 0, 'late' => 1])
            ->and($d['month'])->toMatchArray(['late_count' => 1, 'clients_count' => 1])
            ->and(collect($d['month']['services_top'])->pluck('service')->all())->toBe(['Permit']);

        aaAs($this, $this->super);
        $d = $this->getJson('/api/admin/dashboard')->assertOk()->json('data');
        expect($d['today'])->toMatchArray(['expected' => 3, 'checked_in' => 2, 'missing' => 1, 'late' => 2])
            ->and($d['month'])->toMatchArray(['late_count' => 2, 'clients_count' => 2])
            ->and(collect($d['month']['services_top'])->pluck('service')->all())->toBe(['Visa', 'Permit']);
    });

    it('count no admin before the go-live date nor while the setting is empty', function () {
        aaAs($this, $this->super);
        $this->getJson('/api/admin/dashboard')->assertJsonPath('data.today.expected', 1)->assertJsonPath('data.week.weekly_reports_expected', 1);
        adminsTrackFrom('2026-11-17');
        $this->getJson('/api/admin/dashboard')->assertJsonPath('data.today.expected', 1)->assertJsonPath('data.today.missing', 1);
        expect(collect($this->getJson('/api/admin/attendance/day')->json('data'))->pluck('user.id')->all())->toBe([$this->employee->id]);
        at('2026-11-17 09:00');
        $this->getJson('/api/admin/dashboard')->assertJsonPath('data.today.expected', 3)->assertJsonPath('data.today.missing', 3)
            ->assertJsonPath('data.week.weekly_reports_expected', 3);
    });

    it('remind admins to check in from the go-live date only, never super admins', function () {
        adminsTrackFrom('2026-11-17');
        at('2026-11-16 08:05');
        $this->artisan('attendance:checkin-reminder')->assertSuccessful();
        Notification::assertSentTo($this->employee, CheckinReminder::class);
        Notification::assertNotSentTo([$this->viewer, $this->other, $this->super], CheckinReminder::class);

        at('2026-11-17 08:05');
        gpsCheckIn($this, $this->other)->assertCreated();
        $this->artisan('attendance:checkin-reminder')->assertSuccessful();
        Notification::assertSentTo($this->viewer, CheckinReminder::class);
        Notification::assertNotSentTo([$this->other, $this->super], CheckinReminder::class);
    });

    it('generate daily reports for admins from the go-live date only (catch-up included)', function () {
        adminsTrackFrom('2026-11-17');
        at('2026-11-17 17:00');
        $this->artisan('reports:daily')->assertSuccessful();
        $dates = fn (User $u) => DailyReport::query()->where('user_id', $u->id)->orderBy('work_date')->get()->map(fn ($r) => $r->work_date->toDateString())->all();
        expect($dates($this->employee))->toBe(['2026-11-16', '2026-11-17'])
            ->and($dates($this->other))->toBe(['2026-11-17'])
            ->and($dates($this->viewer))->toBe(['2026-11-17'])
            ->and($dates($this->super))->toBe([]);
    });

    it('count the admin\'s own week from the go-live date only', function () {
        adminsTrackFrom('2026-11-18');
        at('2026-11-20 09:00');
        aaAs($this, $this->other);
        $this->getJson('/api/me/dashboard')->assertOk()->assertJsonPath('data.week.expected_days_so_far', 3)->assertJsonPath('data.week.late_count', 0);
        aaAs($this, $this->employee);
        $this->getJson('/api/me/dashboard')->assertOk()->assertJsonPath('data.week.expected_days_so_far', 5);
    });
});

describe('weekly report', function () {
    beforeEach(function () {
        $this->super = User::factory()->superAdmin()->create();
        $this->receiver = User::factory()->admin(Permission::ReceiveWeeklyReports)->create(['first_name' => 'Rita', 'last_name' => 'Receiver']);
        $this->other = User::factory()->admin()->create(['first_name' => 'Olga', 'last_name' => 'Other']);
        $this->sender = User::factory()->create(['first_name' => 'Sam', 'last_name' => 'Sender']);
        $this->missing = User::factory()->create(['first_name' => 'Mia', 'last_name' => 'Missing']);
    });

    it('owes nothing for a week before the go-live', function () {
        adminsTrackFrom('2026-11-16');
        User::query()->update(['created_at' => '2026-10-01 09:00:00+00']); // everybody was already there last week
        $this->other->refresh();
        $this->sender->refresh();
        aaAs($this, $this->other);
        // Monday: an employee may still send last week's missing report; an admin was not tracked last week.
        $this->getJson('/api/me/reports/weekly/submission')->assertOk()->assertJsonPath('data.can_submit', false)->assertJsonPath('data.week_start', '2026-11-16');
        aaAs($this, $this->sender);
        $this->getJson('/api/me/reports/weekly/submission')->assertOk()->assertJsonPath('data.can_submit', true)->assertJsonPath('data.week_start', '2026-11-09');

        // Catch-up of last Friday's close: no admin is expected or missing for it.
        at('2026-11-16 09:00');
        $this->artisan('reports:weekly-close')->assertSuccessful();
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($this->super->email) && $m->title === 'Weekly reports: 0 of 2 received');
    });

    it('reminds admins on Friday and closes with figures per reader: admins see employees only', function () {
        adminsTrackFrom('2026-11-16');
        DB::table('scheduled_runs')->insert(['task' => 'weekly_close', 'run_key' => 'weekly_close:2026-11-13', 'status' => 'succeeded', 'started_at' => now()]);
        at('2026-11-20 16:20');
        foreach ([$this->sender, $this->receiver] as $u) {
            attend($u, '2026-11-20');
            aaSubmit($this, $u)->assertCreated();
        }

        at('2026-11-20 16:30');
        $this->artisan('reports:weekly-reminder')->assertSuccessful();
        $reminded = fn (User $u) => Notification::sent($u, ReportNotice::class, fn (ReportNotice $n) => $n->kind === 'weekly_report_reminder')->isNotEmpty();
        expect($reminded($this->other))->toBeTrue()->and($reminded($this->missing))->toBeTrue()
            ->and($reminded($this->receiver))->toBeFalse()->and($reminded($this->super))->toBeFalse();

        at('2026-11-20 17:00');
        $this->artisan('reports:weekly-close')->assertSuccessful();
        $this->artisan('reports:weekly-close')->assertSuccessful();
        Mail::assertQueuedCount(2);
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($this->super->email) && $m->title === 'Weekly reports: 2 of 4 received'
            && count($m->submissionIds) === 2 && collect($m->lines)->contains(fn ($l) => str_contains($l, 'Olga Other'))
            && collect($m->lines)->contains(fn ($l) => str_contains($l, 'Rita Receiver')));
        // The admin recipient: employees only – not the other admin, not her own report.
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($this->receiver->email) && $m->title === 'Weekly reports: 1 of 2 received'
            && count($m->submissionIds) === 1 && collect($m->lines)->contains(fn ($l) => str_contains($l, 'Mia Missing'))
            && ! collect($m->lines)->contains(fn ($l) => str_contains($l, 'Olga Other') || str_contains($l, 'Rita Receiver')));

        // Late reports: an admin's goes to super admins only, an employee's to every authorized recipient.
        at('2026-11-20 18:00');
        attend($this->other, '2026-11-20');
        aaSubmit($this, $this->other)->assertCreated()->assertJsonPath('data.is_late', true);
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($this->super->email) && $m->title === 'Late weekly report from Olga Other');
        Mail::assertNotQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($this->receiver->email) && str_contains($m->title, 'Olga Other'));
        attend($this->missing, '2026-11-20');
        aaSubmit($this, $this->missing)->assertCreated();
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($this->receiver->email) && $m->title === 'Late weekly report from Mia Missing');
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($this->super->email) && $m->title === 'Late weekly report from Mia Missing');
    });
});

describe('monthly report', function () {
    it('lists admins for super admins and employees only for an admin recipient; nothing is counted before the go-live', function () {
        $super = User::factory()->superAdmin()->create();
        $recipient = User::factory()->admin()->create(['first_name' => 'Rita', 'last_name' => 'Recipient']);
        $admin = User::factory()->admin()->create(['first_name' => 'Tara', 'last_name' => 'Target']);
        $employee = User::factory()->create(['first_name' => 'Emma', 'last_name' => 'Employee']);
        User::query()->update(['created_at' => '2026-09-01 09:00:00+00']); // accounts older than both periods
        app(SettingsService::class)->set(['monthly_report_recipient_ids' => [$recipient->id]]);
        adminsTrackFrom('2026-11-16');
        at('2026-11-16 08:30');
        gpsCheckIn($this, $admin)->assertCreated()->assertJsonPath('data.is_late', true);
        gpsCheckIn($this, $employee)->assertCreated();

        at('2026-11-26 08:00');
        $this->artisan('reports:monthly')->assertSuccessful();
        $text = fn (string $path) => preg_replace('/\s+/', ' ', (new Parser)->parseContent(Storage::disk('local')->get($path))->getText());

        Mail::assertQueuedCount(2);
        Mail::assertQueued(MonthlyReportMail::class, fn ($m) => $m->hasTo($super->email) && $m->path === 'monthly-reports/2026-11.pdf');
        Mail::assertQueued(MonthlyReportMail::class, fn ($m) => $m->hasTo($recipient->email) && $m->path === 'monthly-reports/2026-11-employees.pdf'
            && $m->fileName === 'Rapport_de_performance_Novembre_2026.pdf');
        expect($text('monthly-reports/2026-11.pdf'))->toContain('TARGET Tara')->toContain('RECIPIENT Rita')->toContain('EMPLOYEE Emma')
            ->and($text('monthly-reports/2026-11-employees.pdf'))->toContain('EMPLOYEE Emma')->not->toContain('TARGET')->not->toContain('RECIPIENT');

        // Before the go-live (setting empty during the whole period): admins are not in the report at all.
        $rows = app(ReportBuilder::class)->monthlyRows('2026-09-26', '2026-10-25');
        expect(collect($rows)->pluck('user_id')->all())->toBe([$employee->id]);
        $rows = collect(app(ReportBuilder::class)->monthlyRows('2026-10-26', '2026-11-25'))->keyBy('user_id');
        expect($rows[$admin->id]['late'])->toBe(1)->and($rows[$recipient->id]['late'])->toBe(0)->and($rows->has($super->id))->toBeFalse();
    });
});
