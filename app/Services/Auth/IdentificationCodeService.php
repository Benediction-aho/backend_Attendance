<?php

namespace App\Services\Auth;

use App\Enums\LogLevel;
use App\Mail\IdentificationCodeMail;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Codes;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Generates a unique identification code, stores only its hash, emails it to the user (queued).
 * The user must replace it after the first sign-in; it expires after the configured delay.
 */
class IdentificationCodeService
{
    public function __construct(private readonly SettingsService $settings, private readonly ActivityLogger $logger) {}

    public function issue(User $user, ?User $by, bool $isNewAccount): void
    {
        $code = Codes::identificationCode($this->settings->int('identification_code_length'));
        $expiresAt = Carbon::now()->addHours($this->settings->int('identification_code_ttl_hours'));

        $user->password = Hash::make($code);
        $user->must_change_password = true;
        $user->initial_code_expires_at = $expiresAt;
        $user->save();

        // A new code ends every existing session of the account.
        $user->tokens()->delete();

        Mail::to($user->email)->queue(new IdentificationCodeMail($user->first_name, $code, $expiresAt, $isNewAccount));

        $this->logger->security('user.code_issued', 'Identification code generated and emailed', LogLevel::Info, $user, $by, [
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }
}
