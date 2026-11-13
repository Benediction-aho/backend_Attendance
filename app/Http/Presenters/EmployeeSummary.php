<?php

namespace App\Http\Presenters;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Compact employee identity for admin lists, with a disambiguator so homonyms can be told apart
 * (UX34-04): job title and type always, the email only for viewers allowed to list user accounts
 * (same rule as UserResource / the Users list). The id is always present as last resort.
 */
final class EmployeeSummary
{
    /** @return array<string, mixed> */
    public static function of(User $u): array
    {
        $data = [
            'id' => $u->id,
            'first_name' => $u->first_name,
            'last_name' => $u->last_name,
            'job_title' => $u->job_title,
            'employee_type' => $u->employee_type->value,
        ];
        if (self::canSeeEmail()) {
            $data['email'] = $u->email;
        }

        return $data;
    }

    private static function canSeeEmail(): bool
    {
        $viewer = request()->user();

        return $viewer instanceof User && Gate::forUser($viewer)->allows('viewAny', User::class);
    }
}
