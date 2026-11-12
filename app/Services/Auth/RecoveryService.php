<?php

namespace App\Services\Auth;

use App\Enums\LogLevel;
use App\Enums\UserStatus;
use App\Mail\RecoveryLinkMail;
use App\Models\LoginAttempt;
use App\Models\LoginCode;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Codes;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Super admin recovery link (security.md section 2): single use, limited lifetime, hashed in database.
 */
class RecoveryService
{
    public function __construct(private readonly SettingsService $settings, private readonly ActivityLogger $logger) {}

    public function send(User $user): void
    {
        $token = Codes::linkToken();
        $minutes = $this->settings->int('recovery_link_ttl_minutes');

        DB::transaction(function () use ($user, $token, $minutes) {
            LoginCode::query()->where('user_id', $user->id)->where('purpose', LoginCode::RECOVERY)->whereNull('used_at')->update(['used_at' => Carbon::now()]);
            LoginCode::query()->create([
                'user_id' => $user->id,
                'purpose' => LoginCode::RECOVERY,
                'code_hash' => Hash::make($token),
                'lookup_hash' => Codes::lookupHash($token),
                'expires_at' => Carbon::now()->addMinutes($minutes),
                'requested_ip' => request()->ip(),
            ]);
        });

        Mail::to($user->email)->queue(new RecoveryLinkMail($user->first_name, $token, $minutes));
        $this->logger->security('auth.recovery_link_sent', 'Recovery link emailed', LogLevel::Warning, $user);
    }

    /** Consumes a recovery token. Returns the restored user, or null if the token is invalid/expired/used. */
    public function consume(string $token): ?User
    {
        $code = LoginCode::query()->where('lookup_hash', Codes::lookupHash($token))->where('purpose', LoginCode::RECOVERY)->first();
        if ($code === null || ! $code->isUsable() || ! Hash::check($token, $code->code_hash)) {
            return null;
        }
        // Single use even under concurrent clicks.
        $claimed = LoginCode::query()->whereKey($code->id)->whereNull('used_at')->update(['used_at' => Carbon::now()]);
        $user = $code->user;
        if ($claimed !== 1 || $user === null || ! $user->isSuperAdmin() || $user->deleted_at !== null) {
            return null;
        }

        LoginAttempt::query()->where('account_key', LockoutService::accountKey($user->email))->delete();
        if ($user->status === UserStatus::Locked) {
            $user->status = UserStatus::Active;
            $user->save();
        }
        $this->logger->security('auth.recovered', 'Access restored with the recovery link', LogLevel::Warning, $user, $user);

        return $user;
    }
}
