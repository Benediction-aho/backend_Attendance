<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\ApiError;
use App\Models\User;
use App\Services\SuperAdminGuard;
use Illuminate\Support\Facades\Gate;

it('gives super admins every permission and employees none', function () {
    $sa = User::factory()->superAdmin()->create();
    $emp = User::factory()->create();

    foreach (Permission::cases() as $p) {
        expect($sa->hasPermission($p))->toBeTrue()->and($emp->hasPermission($p))->toBeFalse();
    }
    expect($sa->permissionKeys())->toHaveCount(count(Permission::cases()))->and($emp->permissionKeys())->toBe([]);
});

it('gives admins only the tiers granted to them', function () {
    $admin = User::factory()->admin(Permission::BlockUsers)->create();

    expect($admin->hasPermission(Permission::BlockUsers))->toBeTrue()
        ->and($admin->hasPermission(Permission::DeleteUsers))->toBeFalse()
        ->and($admin->permissionKeys())->toBe(['block_users']);
});

it('lets admins act on employees only with the matching tier', function (Permission $permission, string $ability) {
    $with = User::factory()->admin($permission)->create();
    $without = User::factory()->admin()->create();
    $employee = User::factory()->create();
    $employee->status = $ability === 'approve' ? UserStatus::Pending : $employee->status;
    $otherAdmin = User::factory()->admin()->create();
    $sa = User::factory()->superAdmin()->create();

    expect(Gate::forUser($with)->allows($ability, $employee))->toBeTrue()
        ->and(Gate::forUser($without)->allows($ability, $employee))->toBeFalse()
        ->and(Gate::forUser($with)->allows($ability, $otherAdmin))->toBeFalse()
        ->and(Gate::forUser($with)->allows($ability, $sa))->toBeFalse()
        ->and(Gate::forUser($sa)->allows($ability, $employee))->toBeTrue();
})->with([
    [Permission::BlockUsers, 'block'],
    [Permission::BlockUsers, 'unlock'],
    [Permission::DeleteUsers, 'delete'],
    [Permission::ApproveUsers, 'approve'],
]);

it('reserves admin creation, permissions and role changes to super admins', function () {
    $admin = User::factory()->admin(...Permission::cases())->create();
    $sa = User::factory()->superAdmin()->create();
    $other = User::factory()->admin()->create();

    expect(Gate::forUser($admin)->allows('create', [User::class, Role::Employee]))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', [User::class, Role::Admin]))->toBeFalse()
        ->and(Gate::forUser($sa)->allows('create', [User::class, Role::SuperAdmin]))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('managePermissions', $other))->toBeFalse()
        ->and(Gate::forUser($sa)->allows('managePermissions', $other))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('changeRole', $other))->toBeFalse()
        ->and(Gate::forUser($sa)->allows('changeRole', $other))->toBeTrue();
});

it('forbids acting on your own account', function () {
    $sa = User::factory()->superAdmin()->create();

    expect(Gate::forUser($sa)->allows('block', $sa))->toBeFalse()
        ->and(Gate::forUser($sa)->allows('delete', $sa))->toBeFalse()
        ->and(Gate::forUser($sa)->allows('changeRole', $sa))->toBeFalse();
});

it('protects the last active super admin', function () {
    $sa = User::factory()->superAdmin()->create();

    expect(fn () => app(SuperAdminGuard::class)->ensureNotLast($sa))->toThrow(ApiError::class);

    User::factory()->superAdmin()->create();
    app(SuperAdminGuard::class)->ensureNotLast($sa);
    expect(true)->toBeTrue();
});

it('does not count blocked or deleted super admins as remaining', function () {
    $sa = User::factory()->superAdmin()->create();
    User::factory()->superAdmin()->status(UserStatus::Blocked)->create();
    User::factory()->superAdmin()->create()->delete();

    expect(fn () => app(SuperAdminGuard::class)->ensureNotLast($sa))->toThrow(ApiError::class);
});
