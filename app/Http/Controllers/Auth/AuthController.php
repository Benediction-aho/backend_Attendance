<?php

namespace App\Http\Controllers\Auth;

use App\Enums\LogLevel;
use App\Exceptions\ApiError;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AccountService;
use App\Services\ActivityLogger;
use App\Services\AtomicLimiter;
use App\Services\Auth\LoginService;
use App\Services\Auth\RecoveryService;
use App\Services\Auth\SuperAdminCodeService;
use App\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AuthController
{
    private const DEVICE_RULES = ['required', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9_-]+$/'];

    public function register(Request $request, AccountService $accounts, SettingsService $settings): JsonResponse
    {
        // Counted before any work (P1-01): parallel bursts can never exceed the hourly limit.
        if (! AtomicLimiter::hit('signup:'.$request->ip(), $settings->int('signup_requests_per_hour'), 3600)) {
            throw new ApiError(429, 'Too many requests. Please try again later.', 'too_many_requests');
        }

        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'employee_type' => ['required', Rule::in(['intern', 'employee'])],
            'job_title' => ['required', 'string', 'max:150'],
        ]);

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
        $data = $request->validate(['email' => ['required', 'string', 'max:254']]);
        $codes->request($data['email'], (string) $request->ip());

        return response()->json(['message' => 'If this email belongs to a super admin account, a sign-in code has been sent.'], 202);
    }

    public function requestRecovery(Request $request, SuperAdminCodeService $codes): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:254']]);
        $codes->requestRecovery($data['email'], (string) $request->ip());

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
        $user->currentAccessToken()->delete();
        $logger->security('auth.logout', 'Signed out', LogLevel::Info, $user, $user);

        return response()->json(['message' => 'Signed out.']);
    }
}
