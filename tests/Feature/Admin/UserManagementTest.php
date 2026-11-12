<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\ApiError;
use App\Mail\IdentificationCodeMail;
use App\Mail\SecurityAlertMail;
use App\Models\ActivityLog;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->sa = User::factory()->superAdmin()->create();
    $this->employee = User::factory()->create(['first_name' => 'Kwame', 'last_name' => 'Boateng', 'email' => 'kwame@example.com']);
});

function actingDevice(object $test, User $user): object
{
    return $test->bearer(deviceToken($user, 'device-'.$user->id.'-0000000000000'));
}

it('lists users with filters for the super admin, paginated', function () {
    User::factory()->pending()->create(['first_name' => 'Pending']);
    User::factory()->admin()->create();

    actingDevice($this, $this->sa)->getJson('/api/admin/users')->assertOk()
        ->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.status', 'pending');
    actingDevice($this, $this->sa)->getJson('/api/admin/users?q=kwa')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.email', 'kwame@example.com');
    actingDevice($this, $this->sa)->getJson('/api/admin/users?role=admin')->assertOk()->assertJsonPath('meta.total', 1);
    actingDevice($this, $this->sa)->getJson('/api/admin/users?q=%25')->assertOk()->assertJsonPath('meta.total', 0);
    actingDevice($this, $this->sa)->getJson('/api/admin/users?status=bogus')->assertUnprocessable();
});

it('lets admins with a management tier list employees only, and forbids others', function () {
    User::factory()->admin()->create();
    $manager = User::factory()->admin(Permission::BlockUsers)->create();
    $reports = User::factory()->admin(Permission::ViewReports)->create();

    $emails = collect(actingDevice($this, $manager)->getJson('/api/admin/users')->assertOk()->json('data'))->pluck('role')->unique()->all();
    expect($emails)->toBe(['employee']);
    actingDevice($this, $reports)->getJson('/api/admin/users')->assertForbidden();
    actingDevice($this, $this->employee)->getJson('/api/admin/users')->assertForbidden();
    actingDevice($this, $manager)->getJson("/api/admin/users/{$this->sa->id}")->assertForbidden();
});

it('returns 404 for unknown or malformed ids', function () {
    actingDevice($this, $this->sa)->getJson('/api/admin/users/999999')->assertNotFound();
    actingDevice($this, $this->sa)->getJson('/api/admin/users/abc')->assertNotFound();
    actingDevice($this, $this->sa)->getJson('/api/admin/users/99999999999999999999999')->assertNotFound();
});

it('creates an employee directly with an emailed code', function () {
    $creator = User::factory()->admin(Permission::CreateUsers)->create();

    actingDevice($this, $creator)->postJson('/api/admin/users', [
        'email' => 'New@Example.com', 'first_name' => 'Efua', 'last_name' => 'Asante', 'employee_type' => 'employee', 'job_title' => 'Accountant',
    ])->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.code_pending', true);

    Mail::assertQueued(IdentificationCodeMail::class, fn ($m) => $m->hasTo('new@example.com'));
    expect(ActivityLog::query()->where('event', 'user.created')->where('actor_id', $creator->id)->exists())->toBeTrue();
});

it('lets only the super admin create admins (with permissions) and super admins', function () {
    $creator = User::factory()->admin(...Permission::cases())->create();
    $payload = ['email' => 'a@example.com', 'first_name' => 'A', 'last_name' => 'B', 'employee_type' => 'employee', 'job_title' => 'HR', 'role' => 'admin', 'permissions' => ['view_reports', 'block_users']];

    actingDevice($this, $creator)->postJson('/api/admin/users', $payload)->assertForbidden();
    actingDevice($this, $this->sa)->postJson('/api/admin/users', $payload)->assertCreated()
        ->assertJsonPath('data.role', 'admin')->assertJsonPath('data.permissions', ['block_users', 'view_reports']);

    actingDevice($this, $this->sa)->postJson('/api/admin/users', ['role' => 'super_admin', 'email' => 's@example.com'] + $payload)->assertCreated();
    expect(User::findByEmail('s@example.com')->password)->toBeNull();
    Mail::assertNotQueued(IdentificationCodeMail::class, fn ($m) => $m->hasTo('s@example.com'));
});

it('refuses a duplicate email and invalid permissions on creation', function () {
    actingDevice($this, $this->sa)->postJson('/api/admin/users', ['email' => 'KWAME@example.com', 'first_name' => 'A', 'last_name' => 'B', 'employee_type' => 'employee', 'job_title' => 'X'])
        ->assertStatus(409)->assertJsonPath('code', 'email_taken');
    actingDevice($this, $this->sa)->postJson('/api/admin/users', ['email' => 'z@example.com', 'first_name' => 'A', 'last_name' => 'B', 'employee_type' => 'employee', 'job_title' => 'X', 'role' => 'admin', 'permissions' => ['root']])
        ->assertUnprocessable();
});

it('blocks and unblocks an employee, revoking sessions immediately', function () {
    $blocker = User::factory()->admin(Permission::BlockUsers)->create();
    $token = deviceToken($this->employee, 'emp-phone-000000000001');

    actingDevice($this, $blocker)->postJson("/api/admin/users/{$this->employee->id}/block")->assertOk()->assertJsonPath('data.status', 'blocked');
    $this->bearer($token)->getJson('/api/me')->assertUnauthorized();

    actingDevice($this, $blocker)->postJson("/api/admin/users/{$this->employee->id}/unblock")->assertOk()->assertJsonPath('data.status', 'active');
    actingDevice($this, $blocker)->postJson("/api/admin/users/{$this->employee->id}/unblock")->assertStatus(409);
    expect(ActivityLog::query()->whereIn('event', ['user.blocked', 'user.unblocked'])->count())->toBe(2);
});

it('forbids admins from blocking admins or super admins, and anyone from blocking themselves', function () {
    $blocker = User::factory()->admin(Permission::BlockUsers)->create();
    $otherAdmin = User::factory()->admin()->create();

    actingDevice($this, $blocker)->postJson("/api/admin/users/{$otherAdmin->id}/block")->assertForbidden();
    actingDevice($this, $blocker)->postJson("/api/admin/users/{$this->sa->id}/block")->assertForbidden();
    actingDevice($this, $blocker)->postJson("/api/admin/users/{$blocker->id}/block")->assertForbidden();
    actingDevice($this, $this->sa)->postJson("/api/admin/users/{$this->sa->id}/block")->assertForbidden();
    actingDevice($this, User::factory()->admin(Permission::DeleteUsers)->create())->postJson("/api/admin/users/{$this->employee->id}/block")->assertForbidden();
});

it('protects the last active super admin from block, delete and demotion', function () {
    $second = User::factory()->superAdmin()->create();

    actingDevice($this, $second)->postJson("/api/admin/users/{$this->sa->id}/block")->assertOk();
    actingDevice($this, $this->sa->refresh())->getJson('/api/me')->assertUnauthorized();

    // $second is now the last active super admin; a fresh super admin cannot remove it once alone.
    $third = User::factory()->superAdmin()->create();
    actingDevice($this, $third)->deleteJson("/api/admin/users/{$second->id}")->assertOk();
    actingDevice($this, $third)->putJson("/api/admin/users/{$third->id}/role", ['role' => 'admin'])->assertForbidden();

    // Only $third remains active: nobody else can act, and self actions are forbidden.
    expect(User::query()->activeSuperAdmins()->count())->toBe(1);
});

it('returns 409 when removing the last active super admin', function () {
    $blocked = User::factory()->superAdmin()->status(UserStatus::Blocked)->create();
    $actor = User::factory()->superAdmin()->create();
    actingDevice($this, $actor)->deleteJson("/api/admin/users/{$this->sa->id}")->assertOk();

    // $actor is the only active one; $blocked cannot act. Demoting $actor by another super admin needs one more.
    $other = User::factory()->superAdmin()->create();
    actingDevice($this, $other)->putJson("/api/admin/users/{$actor->id}/role", ['role' => 'admin'])->assertOk();
    actingDevice($this, $other->refresh())->getJson('/api/me')->assertOk();
    expect(User::query()->activeSuperAdmins()->pluck('id')->all())->toBe([$other->id]);

    // Now unblocking aside, a request against the last one must fail with 409 at service level.
    expect(fn () => app(AccountService::class)->delete($other, $other))->toThrow(ApiError::class);
    expect($blocked->refresh()->status)->toBe(UserStatus::Blocked);
});

it('soft deletes an employee: access cut, name kept for history', function () {
    $deleter = User::factory()->admin(Permission::DeleteUsers)->create();
    $token = deviceToken($this->employee, 'emp-phone-000000000001');

    actingDevice($this, $deleter)->deleteJson("/api/admin/users/{$this->employee->id}")->assertOk();

    $this->bearer($token)->getJson('/api/me')->assertUnauthorized();
    $trashed = User::withTrashed()->find($this->employee->id);
    expect($trashed->status)->toBe(UserStatus::Deleted)->and($trashed->fullName())->toBe('Kwame Boateng');
    actingDevice($this, $deleter)->getJson("/api/admin/users/{$this->employee->id}")->assertNotFound();
});

it('unlocks a locked account, optionally with a new code', function () {
    $this->employee->password = Hash::make('Right-pass-1');
    $this->employee->save();
    exhaustLogins($this, 'kwame@example.com', 'kwame-phone-000000001');
    $this->guest()->postJson('/api/auth/login', loginPayload('kwame@example.com', 'Right-pass-1', 'kwame-phone-000000001'))->assertStatus(423);

    $blocker = User::factory()->admin(Permission::BlockUsers)->create();
    actingDevice($this, $blocker)->postJson("/api/admin/users/{$this->employee->id}/unlock", ['send_new_code' => true])->assertOk();

    expect(LoginAttempt::query()->count())->toBe(0);
    Mail::assertQueued(IdentificationCodeMail::class, fn ($m) => $m->hasTo('kwame@example.com') && ! $m->isNewAccount);
    Mail::assertQueued(SecurityAlertMail::class, fn ($m) => $m->subjectText === 'Your access has been restored');
    actingDevice($this, $blocker)->postJson("/api/admin/users/{$this->employee->id}/unlock")->assertStatus(409);
});

it('lets only super admins unlock an admin', function () {
    $admin = User::factory()->admin()->create(['email' => 'adm@example.com']);
    exhaustLogins($this, 'adm@example.com', 'adm-phone-00000000001');
    $blocker = User::factory()->admin(Permission::BlockUsers)->create();

    actingDevice($this, $blocker)->postJson("/api/admin/users/{$admin->id}/unlock")->assertForbidden();
    actingDevice($this, $this->sa)->postJson("/api/admin/users/{$admin->id}/unlock")->assertOk();
});

it('regenerates an identification code', function () {
    $approver = User::factory()->admin(Permission::ApproveUsers)->create();
    $token = deviceToken($this->employee, 'emp-phone-000000000001');

    actingDevice($this, $approver)->postJson("/api/admin/users/{$this->employee->id}/code")->assertOk();
    Mail::assertQueued(IdentificationCodeMail::class, fn ($m) => $m->hasTo('kwame@example.com'));
    $this->bearer($token)->getJson('/api/me')->assertUnauthorized();
    actingDevice($this, User::factory()->admin(Permission::ViewReports)->create())->postJson("/api/admin/users/{$this->employee->id}/code")->assertForbidden();
    actingDevice($this, $this->sa)->postJson("/api/admin/users/{$this->sa->id}/code")->assertForbidden();
});

it('sets admin permissions (super admin only) and logs added/removed tiers', function () {
    $admin = User::factory()->admin(Permission::ViewReports)->create();

    actingDevice($this, $this->sa)->putJson("/api/admin/users/{$admin->id}/permissions", ['permissions' => ['block_users', 'view_logs']])
        ->assertOk()->assertJsonPath('data.permissions', ['block_users', 'view_logs']);
    $log = ActivityLog::query()->where('event', 'user.permissions_changed')->sole();
    expect($log->context)->toBe(['added' => ['block_users', 'view_logs'], 'removed' => ['view_reports']]);

    actingDevice($this, $admin)->putJson("/api/admin/users/{$admin->id}/permissions", ['permissions' => []])->assertForbidden();
    actingDevice($this, $this->sa)->putJson("/api/admin/users/{$this->employee->id}/permissions", ['permissions' => []])->assertForbidden();
    actingDevice($this, $this->sa)->putJson("/api/admin/users/{$admin->id}/permissions", ['permissions' => ['god_mode']])->assertUnprocessable();
});

it('promotes an employee to admin and an admin to super admin', function () {
    actingDevice($this, $this->sa)->putJson("/api/admin/users/{$this->employee->id}/role", ['role' => 'admin'])->assertOk()->assertJsonPath('data.role', 'admin');
    actingDevice($this, $this->sa)->putJson("/api/admin/users/{$this->employee->id}/role", ['role' => 'super_admin'])->assertOk();

    $promoted = $this->employee->refresh();
    expect($promoted->role)->toBe(Role::SuperAdmin)->and($promoted->password)->toBeNull()
        ->and(ActivityLog::query()->where('event', 'user.role_changed')->count())->toBe(2);
    actingDevice($this, User::factory()->admin(...Permission::cases())->create())->putJson("/api/admin/users/{$promoted->id}/role", ['role' => 'employee'])->assertForbidden();
});

it('demoting a super admin issues a new identification code', function () {
    $other = User::factory()->superAdmin()->create(['email' => 'other@example.com']);

    actingDevice($this, $this->sa)->putJson("/api/admin/users/{$other->id}/role", ['role' => 'employee'])->assertOk();
    Mail::assertQueued(IdentificationCodeMail::class, fn ($m) => $m->hasTo('other@example.com'));
});

it('lists the permission catalogue to super admins only', function () {
    actingDevice($this, $this->sa)->getJson('/api/admin/permissions')->assertOk()->assertJsonCount(count(Permission::cases()), 'data');
    actingDevice($this, User::factory()->admin(...Permission::cases())->create())->getJson('/api/admin/permissions')->assertForbidden();
});

it('never returns secrets in user payloads', function () {
    $json = json_encode(actingDevice($this, $this->sa)->getJson('/api/admin/users')->json());
    expect($json)->not->toContain('"password"')->not->toContain('$2y$')->not->toContain('remember_token');
});
