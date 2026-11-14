<?php

namespace App\Http\Controllers\Auth;

use App\Enums\LogLevel;
use App\Exceptions\ApiError;
use App\Http\Resources\UserResource;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\AccountService;
use App\Services\ActivityLogger;
use App\Services\AtomicLimiter;
use App\Services\Auth\LockoutService;
use App\Services\Auth\LoginService;
use App\Services\Auth\RecoveryService;
use App\Services\Auth\SuperAdminCodeService;
use App\Settings\SettingsService;
use App\Support\ClientIp;
use App\Support\SignupText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuthController
{
    private const DEVICE_RULES = ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'];

    public function register(Request $request, AccountService $accounts, SettingsService $settings): JsonResponse
    {
        // Counted before any work (P1-01): parallel bursts can never exceed the hourly limit.
        // UX2-02: per-IP limit sized for a whole office behind one Wi-Fi, plus a per-email limit.
        $ipKey = 'signup:'.ClientIp::bucket($request->ip());
        if (! AtomicLimiter::hit($ipKey, $settings->int('signup_requests_per_hour'), 3600)) {
            $this->tooManySignups(AtomicLimiter::availableIn($ipKey));
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'regex:'.SignupText::EMAIL_PATTERN],
            'first_name' => ['required', 'string', 'max:100', 'regex:'.SignupText::NAME_PATTERN, 'not_regex:'.SignupText::LINK_PATTERN],
            'last_name' => ['required', 'string', 'max:100', 'regex:'.SignupText::NAME_PATTERN, 'not_regex:'.SignupText::LINK_PATTERN],
            'employee_type' => ['required', Rule::in(['intern', 'employee'])],
            'job_title' => ['required', 'string', 'max:150', 'regex:'.SignupText::JOB_TITLE_PATTERN, 'not_regex:'.SignupText::LINK_PATTERN],
        ], [
            'first_name.regex' => 'Use letters, spaces, hyphens, apostrophes and dots only.',
            'first_name.not_regex' => 'Use letters, spaces, hyphens, apostrophes and dots only (a dot must be followed by a space).',
            'last_name.regex' => 'Use letters, spaces, hyphens, apostrophes and dots only.',
            'last_name.not_regex' => 'Use letters, spaces, hyphens, apostrophes and dots only (a dot must be followed by a space).',
            'job_title.regex' => 'Use letters, digits, spaces and simple punctuation only.',
            'job_title.not_regex' => 'Links are not allowed in the job title.',
        ]);
        // REL-03: global cap, whatever the number of source IPs (counted once the request is well formed).
        $globalKey = 'signup-global';
        if (! AtomicLimiter::hit($globalKey, $settings->int('signup_requests_global_per_hour'), 3600)) {
            $this->tooManySignups(AtomicLimiter::availableIn($globalKey));
        }
        $emailKey = 'signup-email:'.LockoutService::accountKey($data['email']);
        if (! AtomicLimiter::hit($emailKey, $settings->int('signup_requests_per_email_per_hour'), 3600)) {
            $this->tooManySignups(AtomicLimiter::availableIn($emailKey));
        }

        $accounts->register([
            'email' => $data['email'],
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'employee_type' => $data['employee_type'],
            'job_title' => trim($data['job_title']),
        ]);

        // Same answer whether or not the email already exists.
        return response()->json([
            'message' => 'Your request has been sent. You will receive an email with your identification code once an administrator approves it.',
        ], 202);
    }

    private function tooManySignups(int $seconds): never
    {
        throw new ApiError(429, 'Too many sign-up requests. Please try again later or ask an administrator to add you.', 'too_many_requests', ['retry_after_seconds' => $seconds], ['Retry-After' => (string) $seconds]);
    }

    public function login(Request $request, LoginService $login): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
            'device_id' => self::DEVICE_RULES,
            'device_name' => ['nullable', 'string', 'max:120'],
            'is_pwa' => ['nullable', 'boolean'],
        ]);

        $result = $login->attempt($data, (string) $request->ip(), $request->userAgent());
        $request->setUserResolver(fn () => $result['user']);

        return response()->json([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'must_change_password' => $result['user']->must_change_password,
            'user' => new UserResource($result['user']),
        ]);
    }

    public function requestSuperAdminCode(Request $request, SuperAdminCodeService $codes): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            'device_id' => ['nullable', 'string', 'max:200'],
        ]);
        $codes->request($data['email'], (string) $request->ip(), $data['device_id'] ?? null);

        return response()->json(['message' => 'If this email belongs to a super admin account, a sign-in code has been sent.'], 202);
    }

    public function requestRecovery(Request $request, SuperAdminCodeService $codes): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'max:254'],
            'device_id' => ['nullable', 'string', 'max:200'],
        ]);
        $codes->requestRecovery($data['email'], (string) $request->ip(), $data['device_id'] ?? null);

        return response()->json(['message' => 'If a recovery link can be sent for this email, you will receive it shortly.'], 202);
    }

    public function confirmRecovery(Request $request, RecoveryService $recovery): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:200']]);
        if ($recovery->consume($data['token']) === null) {
            throw new ApiError(422, 'This link is invalid or has expired.', 'invalid_link');
        }

        return response()->json(['message' => 'Your access has been restored. You can sign in again.']);
    }

    public function logout(Request $request, ActivityLogger $logger): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $deviceId = $request->attributes->get('device_id');
        if (is_int($deviceId)) {
            // No more pushes to this browser for this account once signed out.
            PushSubscription::query()->where('user_id', $user->id)->where('device_id', $deviceId)->delete();
        }
        $user->currentAccessToken()->delete();
        $logger->security('auth.logout', 'Signed out', LogLevel::Info, $user, $user);

        return response()->json(['message' => 'Signed out.']);
    }
}
