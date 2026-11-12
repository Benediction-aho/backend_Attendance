<?php

namespace App\Http\Controllers;

use App\Enums\LogLevel;
use App\Exceptions\ApiError;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\EmailChangeService;
use App\Services\FileUrls;
use App\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfileController
{
    private const PHONE = ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 ()-]{6,28}$/'];

    public function __construct(private readonly ActivityLogger $logger) {}

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:'.Carbon::now()->toDateString()],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', ...self::PHONE],
            'whatsapp' => ['sometimes', ...self::PHONE],
        ]);

        $user = $this->user($request);
        $user->fill(array_map(fn ($v) => is_string($v) ? trim($v) : $v, $data));
        $changed = array_keys($user->getDirty());
        $user->save();

        if ($changed !== []) {
            $this->logger->log('profile.updated', 'Profile updated', user: $user, actor: $user, context: ['fields' => $changed]);
        }

        return response()->json(['data' => new UserResource($user)]);
    }

    public function uploadPhoto(Request $request, SettingsService $settings): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpeg,png,webp', 'max:'.$settings->int('photo_max_kb'), 'dimensions:max_width=8000,max_height=8000'],
        ]);
        /** @var UploadedFile $file */
        $file = $request->file('photo');
        $extension = match ($file->getMimeType()) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        $user = $this->user($request);
        $disk = Storage::disk(FileUrls::disk());
        // Server-generated name; the original file name is never used.
        $path = $disk->putFileAs('photos/'.$user->id, $file, Str::uuid()->toString().'.'.$extension);
        if ($path === false) {
            throw new ApiError(500, 'The photo could not be saved. Please try again.', 'upload_failed');
        }

        $old = $user->photo_path;
        $user->photo_path = $path;
        $user->save();
        if ($old !== null) {
            $disk->delete($old);
        }
        $this->logger->log('profile.photo_updated', 'Profile photo updated', user: $user, actor: $user);

        return response()->json(['data' => new UserResource($user)]);
    }

    public function deletePhoto(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user->photo_path !== null) {
            Storage::disk(FileUrls::disk())->delete($user->photo_path);
            $user->photo_path = null;
            $user->save();
            $this->logger->log('profile.photo_removed', 'Profile photo removed', user: $user, actor: $user);
        }

        return response()->json(['data' => new UserResource($user)]);
    }

    public function requestEmailChange(Request $request, EmailChangeService $emails): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'current_password' => [$user->isSuperAdmin() ? 'nullable' : 'required', 'string', 'max:200'],
        ]);
        if (! $user->isSuperAdmin() && ($user->password === null || ! Hash::check((string) $data['current_password'], $user->password))) {
            $this->logger->security('profile.email_change_refused', 'Email change refused: wrong password', LogLevel::Warning, $user, $user);
            throw new ApiError(422, 'The current code or password is incorrect.', 'wrong_password');
        }

        $emails->request($user, $data['email']);

        return response()->json([
            'message' => $user->isSuperAdmin()
                ? 'Confirmation links have been sent to your current and new email addresses.'
                : 'A confirmation link has been sent to your new email address.',
        ], 202);
    }

    public function confirmEmailChange(Request $request, EmailChangeService $emails): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:200']]);
        $status = $emails->confirm($data['token']);

        return response()->json([
            'status' => $status,
            'message' => $status === 'applied'
                ? 'Your email address has been changed.'
                : 'Confirmed. Please also confirm from the link sent to your other address.',
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
