<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AtomicLimiter;
use App\Services\Auth\CurrentPasswordGuard;
use App\Services\EmailChangeService;
use App\Services\FileUrls;
use App\Services\ImageSanitizer;
use App\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
        // Re-encoded (no EXIF, no appended bytes) and stored under a server-generated name.
        $bytes = ImageSanitizer::reencode((string) $file->getRealPath(), $extension, $settings->int('photo_max_dimension_px'));
        $path = 'photos/'.$user->id.'/'.Str::uuid()->toString().'.'.$extension;
        if (! $disk->put($path, $bytes)) {
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

    public function requestEmailChange(Request $request, EmailChangeService $emails, CurrentPasswordGuard $guard, SettingsService $settings): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'current_password' => [$user->isSuperAdmin() ? 'nullable' : 'required', 'string', 'max:200'],
        ]);
        if (! $user->isSuperAdmin()) {
            $guard->verify($user, $data['current_password'] ?? null, 'profile.email_change_refused', 'Email change');
        }
        // Throttled per user (counted before sending) (P1-06): bounds the confirmation emails sent to any address.
        if (! AtomicLimiter::hit('email-change:'.$user->id, $settings->int('email_change_requests_per_hour'), 3600)) {
            throw new ApiError(429, 'Too many requests. Please try again later.', 'too_many_requests');
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
