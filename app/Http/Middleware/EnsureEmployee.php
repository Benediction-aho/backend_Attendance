<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Attendance actions belong to employees (employees and interns); staff accounts manage them. */
class EnsureEmployee
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->role !== Role::Employee) {
            return response()->json(['message' => 'This action is only available to employees.', 'code' => 'employees_only'], 403);
        }

        return $next($request);
    }
}
