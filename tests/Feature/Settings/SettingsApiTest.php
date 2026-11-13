<?php

use App\Enums\Permission;
use App\Models\ActivityLog;
use App\Models\SettingHistory;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Settings\SettingDefinitions;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

it('requires authentication', function () {
    $this->getJson('/api/admin/settings')->assertUnauthorized();
});

it('is forbidden to admins without a settings permission and to employees', function (string $who) {
    $others = array_filter(Permission::cases(), fn (Permission $p) => ! str_starts_with($p->value, 'manage_settings_'));
    $user = $who === 'admin'
        ? User::factory()->admin(...$others)->create()
        : User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/admin/settings')->assertForbidden();
    $this->putJson('/api/admin/settings', ['values' => ['checkin_radius_m' => 80]])->assertForbidden();
    $this->getJson('/api/admin/settings/history')->assertForbidden();
})->with(['admin', 'employee']);

it('lets the super admin read all settings', function () {
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $this->getJson('/api/admin/settings')->assertOk()
        ->assertJsonFragment(['key' => 'checkin_radius_m', 'value' => 50, 'default' => 50]);
});

it('lets the super admin change settings now and logs it', function () {
    $sa = User::factory()->superAdmin()->create();
    Sanctum::actingAs($sa);

    $this->putJson('/api/admin/settings', ['values' => ['checkin_radius_m' => 80, 'late_after' => '08:15']])->assertOk();

    app()->forgetScopedInstances();
    expect(app(SettingsService::class)->int('checkin_radius_m'))->toBe(80)
        ->and(ActivityLog::query()->where('event', 'settings.updated')->where('actor_id', $sa->id)->exists())->toBeTrue();
    $this->getJson('/api/admin/settings/history?key=checkin_radius_m')->assertOk()
        ->assertJsonPath('data.0.new_value', 80)->assertJsonPath('data.0.old_value', 50);
});

it('schedules a change for a future date', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-12 10:00', 'Africa/Accra'));
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $this->putJson('/api/admin/settings', ['values' => ['late_after' => '08:30'], 'effective_from' => '2026-11-15'])
        ->assertOk()
        ->assertJsonFragment(['key' => 'late_after', 'value' => '08:10']);

    app()->forgetScopedInstances();
    expect(app(SettingsService::class)->string('late_after', Carbon::parse('2026-11-15 00:00', 'Africa/Accra')))->toBe('08:30');
});

it('rejects invalid values, unknown keys, read-only keys and past dates', function (array $payload) {
    Carbon::setTestNow(Carbon::parse('2026-11-12 10:00', 'Africa/Accra'));
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $this->putJson('/api/admin/settings', $payload)->assertUnprocessable();
})->with([
    'bad radius' => [['values' => ['checkin_radius_m' => -5]]],
    'bad time' => [['values' => ['late_after' => '25:00']]],
    'unknown' => [['values' => ['hack' => 1]]],
    'read only' => [['values' => ['timezone' => 'UTC']]],
    'past date' => [['values' => ['checkin_radius_m' => 60], 'effective_from' => '2026-11-01']],
    'empty' => [['values' => []]],
]);

it('accepts only active admins and super admins as monthly report recipients, and an empty list (P1-09)', function () {
    $sa = User::factory()->superAdmin()->create();
    $admin = User::factory()->admin()->create();
    $employee = User::factory()->create();
    $deleted = User::factory()->admin()->create();
    $deleted->delete();
    Sanctum::actingAs($sa);

    foreach ([$employee->id, $deleted->id, 999999] as $id) {
        $this->putJson('/api/admin/settings', ['values' => ['monthly_report_recipient_ids' => [$id]]])->assertUnprocessable();
    }
    $this->putJson('/api/admin/settings', ['values' => ['monthly_report_recipient_ids' => [$admin->id, $sa->id]]])->assertOk();
    expect(app(SettingsService::class)->intList('monthly_report_recipient_ids'))->toBe([$admin->id, $sa->id]);

    $this->putJson('/api/admin/settings', ['values' => ['monthly_report_recipient_ids' => []]])->assertOk();
    app()->forgetScopedInstances();
    expect(app(SettingsService::class)->intList('monthly_report_recipient_ids'))->toBe([]);
});

it('gives every setting a valid section', function () {
    foreach (SettingDefinitions::all() as $key => $d) {
        expect($d['section'])->toBeIn(SettingDefinitions::SECTIONS, $key);
    }
});

it('shows an admin only the sections they manage', function () {
    Sanctum::actingAs(User::factory()->admin(Permission::ManageSettingsAttendance)->create());

    $data = $this->getJson('/api/admin/settings')->assertOk()->assertJsonPath('sections', ['attendance'])->json('data');
    expect(collect($data)->pluck('section')->unique()->values()->all())->toBe(['attendance'])
        ->and(collect($data)->pluck('key'))->toContain('checkin_radius_m')->not->toContain('otp_length');
});

it('gives the super admin every section and super-admin-only settings', function () {
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $res = $this->getJson('/api/admin/settings')->assertOk()->assertJsonPath('sections', ['attendance', 'reports', 'security']);
    expect(collect($res->json('data'))->pluck('key'))->toContain('monthly_report_recipient_ids')->toContain('otp_length');
});

it('lets an admin change their section, notifies super admins and logs it', function () {
    Notification::fake();
    $sa = User::factory()->superAdmin()->create();
    $admin = User::factory()->admin(Permission::ManageSettingsAttendance)->create();
    Sanctum::actingAs($admin);

    $this->putJson('/api/admin/settings', ['values' => ['checkin_radius_m' => 80]])->assertOk()->assertJsonPath('sections', ['attendance']);

    app()->forgetScopedInstances();
    expect(app(SettingsService::class)->int('checkin_radius_m'))->toBe(80)
        ->and(ActivityLog::query()->where('event', 'settings.updated')->where('actor_id', $admin->id)->exists())->toBeTrue();
    Notification::assertSentTo($sa, StaffAlert::class, fn ($n, $channels) => $n->kind === 'settings_changed' && $channels === ['database', 'mail']);
});

it('does not notify when the super admin changes settings', function () {
    Notification::fake();
    Sanctum::actingAs(User::factory()->superAdmin()->create());

    $this->putJson('/api/admin/settings', ['values' => ['checkin_radius_m' => 80]])->assertOk();
    Notification::assertNothingSent();
});

it('refuses atomically a change touching a section the admin does not manage', function () {
    Sanctum::actingAs(User::factory()->admin(Permission::ManageSettingsAttendance)->create());

    $this->putJson('/api/admin/settings', ['values' => ['checkin_radius_m' => 80, 'otp_length' => 9]])->assertForbidden();

    app()->forgetScopedInstances();
    expect(app(SettingsService::class)->int('checkin_radius_m'))->toBe(50)
        ->and(SettingHistory::query()->count())->toBe(0);
});

it('keeps super-admin-only settings away from admins holding the section', function (string $key, Permission $perm, mixed $value) {
    Sanctum::actingAs(User::factory()->admin($perm)->create());

    expect(collect($this->getJson('/api/admin/settings')->assertOk()->json('data'))->pluck('key'))->not->toContain($key);
    $this->putJson('/api/admin/settings', ['values' => [$key => $value]])->assertForbidden();
})->with([
    ['monthly_report_recipient_ids', Permission::ManageSettingsReports, []],
    ['logs_admins_see_super_admin_events', Permission::ManageSettingsSecurity, true],
]);

it('filters the history to the admin sections', function () {
    $sa = User::factory()->superAdmin()->create();
    Sanctum::actingAs($sa);
    $this->putJson('/api/admin/settings', ['values' => ['checkin_radius_m' => 80, 'otp_length' => 9]])->assertOk();

    Sanctum::actingAs(User::factory()->admin(Permission::ManageSettingsSecurity)->create());
    $res = $this->getJson('/api/admin/settings/history')->assertOk()->assertJsonPath('sections', ['security']);
    expect(collect($res->json('data'))->pluck('key')->all())->toBe(['otp_length']);
});
