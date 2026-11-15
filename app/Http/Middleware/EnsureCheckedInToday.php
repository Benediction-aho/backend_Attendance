<?php

namespace App\Http\Middleware;

use App\Domain\Attendance\AttendanceService;
use App\Domain\Attendance\WorkCalendar;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SPECS 6 "access conditional on check-in": an account that tracks attendance (employee, or admin from the
 * configured date) can do nothing in its own tasks, clients and reports until today's check-in exists. Not applied on days off, leave, holidays and
 * non-working days. Profile, auth, notification and attendance routes never use this middleware.
 */
class EnsureCheckedInToday
{
    public function __construct(private readonly WorkCalendar $calendar, private readonly AttendanceService $attendance) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->tracksAttendance()) {
            return $next($request);
        }

        $date = $this->calendar->workDate();
        if (! $this->calendar->dayStatus($user, $date)->isExpected() || $this->attendance->forDate($user, $date) !== null) {
            return $next($request);
        }

        return response()->json(['message' => 'Please check in before using the application today.', 'code' => 'checkin_required'], 403);
    }
}
