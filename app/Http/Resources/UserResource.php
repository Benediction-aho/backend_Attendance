<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\User;
use App\Services\FileUrls;
use App\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Returns only what the caller may see: personal data for the user and authorized admins,
 * account-management fields for administrators. Never passwords, codes or tokens.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $viewer = $request->user();
        $self = $viewer instanceof User && $viewer->id === $user->id;
        $personal = $viewer instanceof User && Gate::forUser($viewer)->allows('viewPersonalData', $user);
        $admin = $viewer instanceof User && ! $self && Gate::forUser($viewer)->allows('viewAny', User::class);

        $data = [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->fullName(),
            'email' => $user->email,
            'role' => $user->role->value,
            // UX2-18: the type only means something for employees.
            'employee_type' => $user->role === Role::Employee ? $user->employee_type->value : null,
            'job_title' => $user->job_title,
            'status' => $user->status->value,
            'photo_url' => app(FileUrls::class)->temporaryUrl($user->photo_path),
        ];

        if ($personal) {
            $data += [
                'phone' => $user->phone,
                'whatsapp' => $user->whatsapp,
                'birth_date' => $user->birth_date?->toDateString(),
                'address' => $user->address,
            ];
        }

        if ($self) {
            $data += [
                'must_change_password' => $user->must_change_password,
                'permissions' => $user->permissionKeys(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                // For the password form (same rule as StrongPassword).
                'password_min_length' => app(SettingsService::class)->int('password_min_length'),
            ];
        }

        if ($admin) {
            $data += [
                'permissions' => $user->permissionKeys(),
                'code_pending' => $user->must_change_password,
                'code_expires_at' => $user->initial_code_expires_at?->toIso8601String(),
                'approved_at' => $user->approved_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'deleted_at' => $user->deleted_at?->toIso8601String(),
            ];
        }

        return $data;
    }
}
