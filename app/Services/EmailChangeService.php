<?php

namespace App\Services;

use App\Enums\LogLevel;
use App\Exceptions\ApiError;
use App\Mail\EmailChangeConfirmMail;
use App\Mail\SecurityAlertMail;
use App\Models\LoginCode;
use App\Models\User;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Email change (SPECS 5, security.md 2): a confirmation link goes to the new address and an alert to the old one.
 * For super admins the old address must confirm too. The email changes only when every link is confirmed.
 */
class EmailChangeService
{
    public function __construct(private readonly SettingsService $settings, private readonly ActivityLogger $logger) {}

    public function request(User $user, string $newEmail): void
    {
        $newEmail = User::normalizeEmail($newEmail);
        if ($newEmail === $user->email) {
            throw new ApiError(422, 'This is already your email address.', 'same_email');
        }
        if (User::findByEmail($newEmail) !== null) {
            throw new ApiError(422, 'This email address cannot be used.', 'email_unavailable');
        }

        $minutes = $this->settings->int('email_change_ttl_minutes');
        $changeId = (string) Str::uuid();
        $purposes = $user->isSuperAdmin() ? [LoginCode::EMAIL_CHANGE_NEW, LoginCode::EMAIL_CHANGE_OLD] : [LoginCode::EMAIL_CHANGE_NEW];
        $tokens = [];

        DB::transaction(function () use ($user, $newEmail, $minutes, $changeId, $purposes, &$tokens) {
            LoginCode::query()->where('user_id', $user->id)
                ->whereIn('purpose', [LoginCode::EMAIL_CHANGE_NEW, LoginCode::EMAIL_CHANGE_OLD])
                ->whereNull('used_at')->update(['used_at' => Carbon::now(), 'meta->cancelled' => true]);

            foreach ($purposes as $purpose) {
                $token = Codes::linkToken();
                $tokens[$purpose] = $token;
                LoginCode::query()->create([
                    'user_id' => $user->id,
                    'purpose' => $purpose,
                    'code_hash' => Hash::make($token),
                    'lookup_hash' => Codes::lookupHash($token),
                    'meta' => ['change_id' => $changeId, 'new_email' => $newEmail],
                    'expires_at' => Carbon::now()->addMinutes($minutes),
                    'requested_ip' => request()->ip(),
                ]);
            }
        });

        Mail::to($newEmail)->queue(new EmailChangeConfirmMail($user->first_name, $tokens[LoginCode::EMAIL_CHANGE_NEW], $newEmail, $minutes, false));
        if (isset($tokens[LoginCode::EMAIL_CHANGE_OLD])) {
            Mail::to($user->email)->queue(new EmailChangeConfirmMail($user->first_name, $tokens[LoginCode::EMAIL_CHANGE_OLD], $newEmail, $minutes, true));
        } else {
            Mail::to($user->email)->queue(new SecurityAlertMail($user->first_name, 'Email change requested', [
                "A change of your sign-in email to {$newEmail} was requested. It will apply once the new address is confirmed.",
            ]));
        }

        $this->logger->security('profile.email_change_requested', 'Email change requested', LogLevel::Info, $user, $user, ['new_email' => $newEmail]);
    }

    /**
     * Confirms one link. Returns 'applied' when the change is complete, 'pending' when another confirmation is still needed.
     */
    public function confirm(string $token): string
    {
        $code = LoginCode::query()->where('lookup_hash', Codes::lookupHash($token))
            ->whereIn('purpose', [LoginCode::EMAIL_CHANGE_NEW, LoginCode::EMAIL_CHANGE_OLD])->first();
        if ($code === null || ! $code->isUsable() || ! Hash::check($token, $code->code_hash)) {
            throw new ApiError(422, 'This link is invalid or has expired.', 'invalid_link');
        }

        return DB::transaction(function () use ($code) {
            $claimed = LoginCode::query()->whereKey($code->id)->whereNull('used_at')->update(['used_at' => Carbon::now()]);
            if ($claimed !== 1) {
                throw new ApiError(422, 'This link is invalid or has expired.', 'invalid_link');
            }
            $changeId = $code->meta['change_id'] ?? null;
            $newEmail = (string) ($code->meta['new_email'] ?? '');

            $remaining = LoginCode::query()->where('user_id', $code->user_id)
                ->where('meta->change_id', $changeId)->whereNull('used_at')->count();
            if ($remaining > 0) {
                return 'pending';
            }

            /** @var User $user */
            $user = User::query()->whereKey($code->user_id)->lockForUpdate()->firstOrFail();
            if (User::findByEmail($newEmail) !== null) {
                throw new ApiError(422, 'This email address cannot be used.', 'email_unavailable');
            }
            $old = $user->email;
            $user->email = $newEmail;
            $user->save();

            Mail::to($old)->queue(new SecurityAlertMail($user->first_name, 'Your sign-in email was changed', [
                "Your sign-in email is now {$newEmail}. This address will no longer be used for your account.",
            ]));
            $this->logger->security('profile.email_changed', 'Email address changed', LogLevel::Warning, $user, $user, ['old_email' => $old, 'new_email' => $newEmail]);

            return 'applied';
        });
    }
}
