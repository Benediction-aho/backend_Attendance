<?php

namespace Database\Factories;

use App\Enums\EmployeeType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\PermissionRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::Employee,
            'employee_type' => EmployeeType::Employee,
            'job_title' => 'Sales Officer',
            'status' => UserStatus::Active,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (User $user) {
            if ($user->role !== Role::SuperAdmin && $user->password === null) {
                $user->password = static::$password ??= Hash::make('Password-123');
            }
        });
    }

    public function withPassword(string $password): static
    {
        return $this->afterMaking(fn (User $user) => $user->password = Hash::make($password));
    }

    public function superAdmin(): static
    {
        return $this->state(['role' => Role::SuperAdmin]);
    }

    public function admin(Permission ...$permissions): static
    {
        return $this->state(['role' => Role::Admin])->afterCreating(function (User $user) use ($permissions) {
            if ($permissions !== []) {
                $ids = PermissionRecord::query()->whereIn('key', array_map(fn ($p) => $p->value, $permissions))->pluck('id');
                $user->permissionRecords()->attach($ids);
            }
        });
    }

    public function pending(): static
    {
        return $this->state(['status' => UserStatus::Pending])->afterMaking(fn (User $u) => $u->password = null);
    }

    public function status(UserStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
