<?php

namespace App\Console\Commands;

use App\Enums\EmployeeType;
use App\Enums\LogLevel;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Mail\SecurityAlertMail;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

/**
 * Creates the first super admin, once, from the server (there is no public setup page).
 * The super admin has no password: each sign-in uses a one-time code sent by email.
 */
class CreateSuperAdmin extends Command
{
    protected $signature = 'attendance:create-super-admin
        {--email= : Email address}
        {--first-name= : First name}
        {--last-name= : Last name}';

    protected $description = 'Create the first super admin (only when no super admin exists)';

    public function handle(ActivityLogger $logger): int
    {
        if (User::withTrashed()->where('role', Role::SuperAdmin->value)->whereNull('deleted_at')->exists()) {
            $this->error('A super admin already exists. Additional super admins are created from the application.');

            return self::FAILURE;
        }

        $input = [
            'email' => $this->option('email') ?: ($this->input->isInteractive() ? $this->ask('Email address') : null),
            'first_name' => $this->option('first-name') ?: ($this->input->isInteractive() ? $this->ask('First name') : null),
            'last_name' => $this->option('last-name') ?: ($this->input->isInteractive() ? $this->ask('Last name') : null),
        ];

        $validator = Validator::make($input, [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        if (User::findByEmail((string) $input['email']) !== null) {
            $this->error('An account with this email already exists.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($input) {
            // Serialise concurrent runs of the command.
            DB::statement('LOCK TABLE users IN SHARE ROW EXCLUSIVE MODE');
            if (User::query()->where('role', Role::SuperAdmin->value)->exists()) {
                return null;
            }
            $user = User::query()->create([
                'email' => (string) $input['email'],
                'first_name' => trim((string) $input['first_name']),
                'last_name' => trim((string) $input['last_name']),
                'role' => Role::SuperAdmin,
                'employee_type' => EmployeeType::Employee,
                'job_title' => 'Super admin',
                'status' => UserStatus::Active,
            ]);
            $user->approved_at = Carbon::now();
            $user->save();

            return $user;
        });

        if ($user === null) {
            $this->error('A super admin already exists.');

            return self::FAILURE;
        }

        $logger->security('user.super_admin_installed', 'First super admin created from the server console', LogLevel::Warning, $user);
        Mail::to($user->email)->queue(new SecurityAlertMail($user->first_name, 'Your super admin account is ready', [
            'The first super admin account of Assistance Ghana Attendance was created for you.',
            'To sign in, enter your email address: a one-time code will be emailed to you each time.',
        ]));

        $this->info("Super admin created for {$user->email}. Sign in with the email address: a one-time code is emailed each time.");

        return self::SUCCESS;
    }
}
