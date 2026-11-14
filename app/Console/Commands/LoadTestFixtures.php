<?php

namespace App\Console\Commands;

use App\Enums\EmployeeType;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Device;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates N test employees with device tokens for the load test (tests/load). Refused in production.
 */
class LoadTestFixtures extends Command
{
    protected $signature = 'attendance:load-fixtures {--count=200} {--output=storage/app/private/load-tokens.json}';

    protected $description = 'Create load-test employees and tokens (never in production)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refused in production.');

            return self::FAILURE;
        }

        $count = max(1, (int) $this->option('count'));
        $password = Hash::make(Str::random(32));
        $tokens = [];
        for ($i = 1; $i <= $count; $i++) {
            $email = sprintf('load-%04d@loadtest.invalid', $i);
            $user = User::query()->where('email', $email)->first() ?? new User;
            $user->fill([
                'first_name' => 'Load', 'last_name' => sprintf('Test %04d', $i), 'email' => $email,
                'role' => Role::Employee, 'employee_type' => EmployeeType::Employee, 'status' => UserStatus::Active,
            ]);
            $user->password = $password;
            $user->must_change_password = false;
            $user->save();
            $user->tokens()->delete();
            $device = Device::query()->firstOrCreate(
                ['user_id' => $user->id, 'identifier_hash' => Device::hashIdentifier('load-device-'.$i)],
                ['first_login_at' => now(), 'last_seen_at' => now()],
            );
            $token = $user->createToken('load-test');
            $token->accessToken->forceFill(['device_id' => $device->id])->save();
            $tokens[] = $token->plainTextToken;
        }

        $path = base_path((string) $this->option('output'));
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, json_encode($tokens));
        chmod($path, 0600);
        $this->info("Created {$count} employees; tokens written to {$path}");

        return self::SUCCESS;
    }
}
