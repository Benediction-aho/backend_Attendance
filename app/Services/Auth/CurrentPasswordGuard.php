<?php

namespace App\Services\Auth;

use App\Enums\LogLevel;
use App\Exceptions\ApiError;
use App\Mail\SecurityAlertMail;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Current-password checks of a signed-in user (P1-03): same lockout series as sign-in (5 / 5 / 5, from settings),
 * reserved before the hash is checked. When a series completes, the session is signed out, the event is logged
 * and the user is alerted by email: a stolen session cannot be used to brute-force the personal password.
 */
class CurrentPasswordGuard
{
    public function __construct(private readonly LockoutService $lockout, private readonly ActivityLogger $logger) {}

    public function verify(User $user, ?string $password, string $event, string $what): void
    {
        $accountKey = LockoutService::accountKey($user->email);
        $bucket = 'user:'.$user->id;

        try {
            $outcome = $this->lockout->reserve($accountKey, LoginAttempt::SCOPE_REAUTH, $bucket, $user);
        } catch (ApiError $e) {
            $this->signOut($user, 'refused', false);
            throw $e;
        }

        if ($user->password !== null && $password !== null) {
            $valid = Hash::check($password, $user->password);
        } else {
            Hash::check((string) $password, LoginService::dummyHash());
            $valid = false;
        }

        if ($valid) {
            $this->lockout->reset($accountKey, LoginAttempt::SCOPE_REAUTH, $bucket);

            return;
        }

        $this->logger->security($event, "{$what} refused: wrong current code or password", LogLevel::Warning, $user, $user, ['series_outcome' => $outcome]);
        if ($outcome === 'failed') {
            throw new ApiError(422, 'The current code or password is incorrect.', 'wrong_password');
        }

        $this->signOut($user, $outcome, true);
        throw new ApiError(401, 'Too many incorrect attempts. You have been signed out for security reasons.', 'reauth_limit');
    }

    private function signOut(User $user, string $outcome, bool $alert): void
    {
        $user->tokens()->whereKey($user->currentAccessToken()->getKey())->delete();
        $this->logger->security('auth.reauth_limit', 'Signed out after repeated wrong current-password answers', LogLevel::Critical, $user, $user, ['outcome' => $outcome]);
        if ($alert) {
            Mail::to($user->email)->queue(new SecurityAlertMail($user->first_name, 'Suspicious activity on your account', [
                'Someone signed in to your account entered a wrong current password several times. That device has been signed out.',
                'If this was not you, change your password and contact an administrator.',
            ]));
        }
    }
}
