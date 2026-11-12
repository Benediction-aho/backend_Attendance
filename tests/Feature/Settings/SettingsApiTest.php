<?php

use App\Enums\Permission;
use App\Models\ActivityLog;
use App\Models\User;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

it('requires authentication', function () {
    $this->getJson('/api/admin/settings')->assertUnauthorized();
});

it('is forbidden to admins (even with every permission) and employees', function (string $who) {
    $user = $who === 'admin'
        ? User::factory()->admin(...Permission::cases())->create()
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
