<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attendance actions (check-in, working week, tasks, clients, reports, Face ID) belong to the accounts
 * that track attendance today: employees and, from the configured date, admins. Never super admins.
 */
class EnsureTracksAttendance
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->tracksAttendance()) {
            // The code is unchanged for the PWA ("employees_only" = this account does not track attendance).
            return response()->json(['message' => 'This action is only available to accounts that track attendance.', 'code' => 'employees_only'], 403);
        }

        return $next($request);
    }
}
