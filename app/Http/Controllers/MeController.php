<?php

namespace App\Http\Controllers;

use App\Enums\LogLevel;
use App\Exceptions\ApiError;
use App\Http\Resources\UserResource;
use App\Mail\SecurityAlertMail;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class MeController
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => new UserResource($request->user())]);
    }

    /** Replace the identification code (or current password) with a personal password. */
    public function password(Request $request, ActivityLogger $logger): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->isSuperAdmin()) {
            throw new ApiError(422, 'Super admins sign in with one-time codes and have no password.', 'no_password');
        }

        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'new_password' => ['required', 'string', 'max:200', 'confirmed', new StrongPassword],
        ]);

        if ($user->password === null || ! Hash::check($data['current_password'], $user->password)) {
            $logger->security('auth.password_change_failed', 'Password change refused: wrong current code', LogLevel::Warning, $user, $user);
            throw new ApiError(422, 'The current code or password is incorrect.', 'wrong_password');
        }
        if (Hash::check($data['new_password'], $user->password)) {
            throw new ApiError(422, 'The new password must be different from the current one.', 'same_password');
        }

        $user->password = Hash::make($data['new_password']);
        $user->must_change_password = false;
        $user->initial_code_expires_at = null;
        $user->password_changed_at = Carbon::now();
        $user->save();

        // Every other device is signed out; this one stays signed in.
        $current = $user->currentAccessToken();
        $user->tokens()->where('id', '!=', $current->getKey())->delete();

        $logger->security('auth.password_changed', 'Password changed', LogLevel::Info, $user, $user);
        Mail::to($user->email)->queue(new SecurityAlertMail($user->first_name, 'Your password was changed', [
            'The password of your Assistance Ghana Attendance account was just changed. Your other devices have been signed out.',
        ]));

        return response()->json(['message' => 'Your password has been changed.', 'data' => new UserResource($user)]);
    }
}
