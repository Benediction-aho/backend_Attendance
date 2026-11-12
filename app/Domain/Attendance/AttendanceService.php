<?php

namespace App\Domain\Attendance;

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Enums\Permission;
use App\Exceptions\ApiError;
use App\Models\Attendance;
use App\Models\CheckinAttempt;
use App\Models\CheckinIntent;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\ActivityLogger;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Check-in / check-out rules (SPECS 6, FIABILITE B1/B2/B4).
 *
 * A check-in is two steps: an intent (position checked by the server, or not needed for mission/remote),
 * then a confirmation that will carry the Face ID assertion (phase 4). The attendance row is written with
 * INSERT … ON CONFLICT DO NOTHING on (user_id, work_date): double submits and parallel requests never
 * create a duplicate nor an error.
 */
class AttendanceService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly WorkCalendar $calendar,
        private readonly ActivityLogger $logger,
        private readonly CheckinAssertionVerifier $verifier,
    ) {}

    public function today(User $user): ?Attendance
    {
        return $this->forDate($user, $this->calendar->workDate());
    }

    public function forDate(User $user, string $date): ?Attendance
    {
        return Attendance::query()->where('user_id', $user->id)->whereDate('work_date', $date)->first();
    }

    /**
     * Step 1. $position = [lat, lng, accuracy] (required for gps, optional otherwise).
     *
     * @param  array{lat: float, lng: float, accuracy: float}|null  $position
     */
    public function createIntent(User $user, string $type, ?array $position, ?string $reason, ?int $deviceId, ?string $ip): CheckinIntent
    {
        $now = $this->calendar->now();
        $date = $this->calendar->workDate($now);

        if (($existing = $this->forDate($user, $date)) !== null) {
            throw new ApiError(409, 'You have already checked in today.', 'already_checked_in', ['attendance_id' => $existing->id]);
        }

        $distance = $position !== null ? $this->distanceToOffice($position['lat'], $position['lng'], $now) : null;

        if ($type === Attendance::TYPE_GPS) {
            if ($position === null) {
                throw new ApiError(422, 'Your location is required to check in.', 'location_required');
            }
            $distance = $this->distanceToOffice($position['lat'], $position['lng'], $now);
            $radius = $this->settings->int('checkin_radius_m', $now);
            $maxAccuracy = $this->settings->int('checkin_max_accuracy_m', $now);
            if ($position['accuracy'] > $maxAccuracy) {
                $this->refuse($user, $date, 'low_accuracy', $position, $distance, $radius, $maxAccuracy, $deviceId, $ip);
                throw new ApiError(422, 'Your location is not precise enough yet. Please wait a moment and try again.', 'low_accuracy', [
                    'accuracy_m' => round($position['accuracy'], 1), 'max_accuracy_m' => $maxAccuracy,
                ]);
            }
            if ($distance > $radius) {
                $this->refuse($user, $date, 'out_of_perimeter', $position, $distance, $radius, $maxAccuracy, $deviceId, $ip);
                throw new ApiError(422, 'You are outside the office perimeter.', 'out_of_perimeter', [
                    'distance_m' => round($distance), 'radius_m' => $radius,
                ]);
            }
        } elseif ($type === Attendance::TYPE_REMOTE) {
            if ($this->calendar->dayStatus($user, $date) !== DayStatus::Remote) {
                throw new ApiError(422, 'Today is not one of your remote working days.', 'not_remote_day');
            }
        } elseif ($type === Attendance::TYPE_MISSION) {
            if ($reason === null || trim($reason) === '') {
                throw new ApiError(422, 'Please give the reason for your mission.', 'reason_required');
            }
        }

        /** @var CheckinIntent $intent */
        $intent = CheckinIntent::query()->create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'type' => $type,
            'work_date' => $date,
            'lat' => $position['lat'] ?? null,
            'lng' => $position['lng'] ?? null,
            'accuracy_m' => $position['accuracy'] ?? null,
            'distance_m' => $distance,
            'reason' => $reason !== null ? trim($reason) : null,
            'expires_at' => $now->copy()->addSeconds($this->settings->int('checkin_intent_ttl_seconds', $now)),
            'created_at' => $now,
        ]);

        return $intent;
    }

    /**
     * Step 2: confirm the intent (single use, short-lived). Returns [attendance, created].
     *
     * @param  array<string, mixed>|null  $assertion
     * @return array{0: Attendance, 1: bool}
     */
    public function confirm(User $user, string $intentId, ?array $assertion, ?int $deviceId = null): array
    {
        $now = $this->calendar->now();
        $date = $this->calendar->workDate($now);

        /** @var CheckinIntent|null $intent */
        $intent = CheckinIntent::query()->whereKey($intentId)->where('user_id', $user->id)->first();
        // P3-03: the position check and the confirmation must come from the same device session.
        if ($intent === null || ($intent->device_id !== null ? (int) $intent->device_id : null) !== $deviceId) {
            throw new ApiError(422, 'This check-in request is no longer valid. Please start again.', 'intent_invalid');
        }

        $faceIdRequired = $this->settings->bool('faceid_required_for_checkin', $now);
        $verified = null;
        if ($assertion !== null) {
            $verified = $this->verifier->verify($user, $intent, $assertion);
        }
        if ($faceIdRequired && $verified === null) {
            // A double submit of an already accepted confirmation returns the existing attendance (no error).
            if (CheckinIntent::query()->whereKey($intent->id)->whereNotNull('consumed_at')->exists() && ($existing = $this->forDate($user, $date)) !== null) {
                return [$existing, false];
            }
            $this->logger->security('attendance.faceid_failed', 'Check-in refused: Face ID verification failed', LogLevel::Warning, $user);
            throw new ApiError(422, 'Face ID verification failed. Please try again.', 'faceid_failed');
        }

        // Claim and insert in one transaction: a parallel double submit waits on the intent row lock,
        // then sees it consumed and the attendance committed (READ COMMITTED re-evaluation).
        // The Face ID challenge is consumed in the same transaction (single use, bound to this intent).
        $result = DB::transaction(function () use ($intent, $user, $date, $now, $verified) {
            $claimed = CheckinIntent::query()->whereKey($intent->id)->whereNull('consumed_at')
                ->where('expires_at', '>', $now)->whereDate('work_date', $date)
                ->update(['consumed_at' => $now]);
            if ($claimed !== 1) {
                return null;
            }
            if ($verified !== null && ! $this->verifier->markUsed($verified)) {
                // Unreachable in practice (the challenge belongs to this intent only); rolls the claim back.
                throw new ApiError(422, 'Face ID verification failed. Please try again.', 'faceid_failed');
            }

            return $this->insert($user, $date, $now, [
                'check_in_type' => $intent->type,
                'check_in_lat' => $intent->lat,
                'check_in_lng' => $intent->lng,
                'check_in_accuracy_m' => $intent->accuracy_m,
                'check_in_distance_m' => $intent->distance_m,
                'faceid_verified' => $verified !== null,
                'webauthn_credential_id' => $verified?->credentialId,
                'reason' => $intent->reason,
                'mission_status' => $intent->type === Attendance::TYPE_MISSION ? 'pending' : null,
            ]);
        });
        if ($result === null) {
            // Double submit: the first confirmation already wrote today's attendance.
            $consumed = CheckinIntent::query()->whereKey($intent->id)->whereNotNull('consumed_at')->exists();
            if ($consumed && ($existing = $this->forDate($user, $date)) !== null) {
                return [$existing, false];
            }
            throw new ApiError(422, 'This check-in request has expired. Please start again.', 'intent_invalid');
        }

        if ($result[1]) {
            $attendance = $result[0];
            $this->logger->log('attendance.check_in', "Checked in ({$attendance->check_in_type})", LogType::Action, LogLevel::Info, $user, $user, [
                'attendance_id' => $attendance->id, 'type' => $attendance->check_in_type, 'late' => $attendance->is_late,
            ]);
            if ($attendance->check_in_type === Attendance::TYPE_MISSION) {
                $this->notifyStaff(Permission::ApproveMissionCheckins, new StaffAlert(
                    'mission_checkin',
                    'Mission check-in to approve',
                    "{$user->fullName()} checked in on a mission: {$attendance->reason}",
                    ['attendance_id' => $attendance->id, 'user_id' => $user->id],
                    false,
                    '/admin/attendance/missions',
                ));
            }
        }

        return $result;
    }

    /** Fallback check-in by an authorized admin (phone broken, Face ID impossible), with a reason. */
    public function checkInOnBehalf(User $actor, User $employee, string $reason): Attendance
    {
        $now = $this->calendar->now();
        $date = $this->calendar->workDate($now);

        [$attendance, $created] = $this->insert($employee, $date, $now, [
            'check_in_type' => Attendance::TYPE_ADMIN,
            'reason' => trim($reason),
            'checked_in_by' => $actor->id,
        ]);
        if (! $created) {
            throw new ApiError(409, 'This employee has already checked in today.', 'already_checked_in', ['attendance_id' => $attendance->id]);
        }

        $this->logger->log('attendance.admin_check_in', 'Checked in on behalf of the employee by an admin', LogType::Action, LogLevel::Warning, $employee, $actor, [
            'attendance_id' => $attendance->id, 'reason' => $attendance->reason,
        ]);

        return $attendance;
    }

    /**
     * @param  array{lat: float, lng: float, accuracy: float}|null  $position
     */
    public function checkOut(User $user, ?array $position, ?string $reason): Attendance
    {
        $now = $this->calendar->now();
        $date = $this->calendar->workDate($now);
        $attendance = $this->forDate($user, $date);
        if ($attendance === null) {
            throw new ApiError(409, 'You have not checked in today.', 'not_checked_in');
        }
        if ($attendance->check_out_at !== null) {
            throw new ApiError(409, 'You have already checked out today.', 'already_checked_out');
        }

        $endOfDay = $this->settings->string('end_of_day_time', $now);
        $early = $now->lt($this->calendar->at($date, $endOfDay));
        $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
        if ($early && $reason === null && $this->settings->bool('early_checkout_reason_required', $now)) {
            throw new ApiError(422, "Please give a reason for leaving before {$endOfDay}.", 'reason_required', ['end_of_day' => $endOfDay]);
        }

        $distance = $position !== null ? $this->distanceToOffice($position['lat'], $position['lng'], $now) : null;
        $updated = Attendance::query()->whereKey($attendance->id)->whereNull('check_out_at')->update([
            'check_out_at' => $now,
            'check_out_type' => Attendance::CHECKOUT_MANUAL,
            'check_out_lat' => $position['lat'] ?? null,
            'check_out_lng' => $position['lng'] ?? null,
            'check_out_accuracy_m' => $position['accuracy'] ?? null,
            'check_out_distance_m' => $distance,
            'early_leave' => $early,
            'early_leave_reason' => $early ? $reason : null,
            'end_of_day' => $endOfDay,
            'updated_at' => $now,
        ]);
        if ($updated !== 1) {
            throw new ApiError(409, 'You have already checked out today.', 'already_checked_out');
        }
        $attendance->refresh();

        $this->logger->log('attendance.check_out', $early ? 'Checked out early' : 'Checked out', LogType::Action, LogLevel::Info, $user, $user, [
            'attendance_id' => $attendance->id, 'early' => $early,
        ]);
        if ($early) {
            $this->notifyStaff(Permission::ViewLateness, new StaffAlert(
                'early_checkout',
                'Early check-out',
                "{$user->fullName()} checked out at {$now->format('H:i')}: {$reason}",
                ['attendance_id' => $attendance->id, 'user_id' => $user->id],
                false,
                '/admin/attendance',
            ));
        }

        return $attendance;
    }

    public function decideMission(User $actor, Attendance $attendance, bool $approve, ?string $note): Attendance
    {
        $updated = Attendance::query()->whereKey($attendance->id)
            ->where('check_in_type', Attendance::TYPE_MISSION)->where('mission_status', 'pending')
            ->update([
                'mission_status' => $approve ? 'approved' : 'rejected',
                'mission_validated_by' => $actor->id,
                'mission_validated_at' => Carbon::now(),
                'mission_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                'updated_at' => Carbon::now(),
            ]);
        if ($updated !== 1) {
            throw new ApiError(409, 'This mission check-in has already been reviewed.', 'mission_already_reviewed');
        }
        $attendance->refresh();
        $employee = $attendance->user;
        $this->logger->log($approve ? 'attendance.mission_approved' : 'attendance.mission_rejected', $approve ? 'Mission check-in approved' : 'Mission check-in rejected', LogType::Action, LogLevel::Info, $employee, $actor, [
            'attendance_id' => $attendance->id,
        ]);
        if ($employee !== null) {
            $employee->notify(new StaffAlert(
                $approve ? 'mission_approved' : 'mission_rejected',
                $approve ? 'Mission check-in approved' : 'Mission check-in rejected',
                'Your mission check-in of '.$attendance->work_date->toDateString().($approve ? ' was approved.' : ' was rejected.'),
                ['attendance_id' => $attendance->id],
                false,
                '/attendance',
            ));
        }

        return $attendance;
    }

    /**
     * Closes every open attendance whose automatic check-out instant has passed ("check-out forgotten").
     * Safe to repeat: only rows still open are touched.
     */
    public function closeOpenAttendances(string $date, Carbon $at): int
    {
        return DB::update(
            'UPDATE attendances SET check_out_at = GREATEST(check_in_at, ?::timestamptz), check_out_type = ?, updated_at = ? WHERE work_date = ? AND check_out_at IS NULL',
            [$at->toIso8601String(), Attendance::CHECKOUT_AUTO, Carbon::now()->toIso8601String(), $date],
        );
    }

    public function distanceToOffice(float $lat, float $lng, ?Carbon $at = null): float
    {
        return Geo::distanceM(
            (float) $this->settings->get('office_latitude', $at),
            (float) $this->settings->get('office_longitude', $at),
            $lat,
            $lng,
        );
    }

    /**
     * INSERT … ON CONFLICT (user_id, work_date) DO NOTHING. Returns the row and whether this call created it.
     *
     * @param  array<string, mixed>  $values
     * @return array{0: Attendance, 1: bool}
     */
    private function insert(User $user, string $date, Carbon $now, array $values): array
    {
        $status = $this->calendar->dayStatus($user, $date);
        $lateAfter = $this->settings->string('late_after', $now);
        $isLate = $status->isExpected() && $now->gte($this->calendar->at($date, $lateAfter));

        $row = array_merge([
            'user_id' => $user->id,
            'work_date' => $date,
            'day_status' => $status->value,
            'check_in_at' => $now->toIso8601String(),
            'is_late' => $isLate,
            'late_after' => $lateAfter,
            'faceid_verified' => false,
            'created_at' => $now->toIso8601String(),
            'updated_at' => $now->toIso8601String(),
        ], $values);

        $columns = array_keys($row);
        $sql = 'INSERT INTO attendances ('.implode(', ', $columns).') VALUES ('.implode(', ', array_fill(0, count($columns), '?')).')'
            .' ON CONFLICT (user_id, work_date) DO NOTHING RETURNING id';
        $bindings = array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, array_values($row));
        $inserted = DB::selectOne($sql, $bindings);

        if ($inserted !== null) {
            return [Attendance::query()->whereKey((int) $inserted->id)->firstOrFail(), true];
        }

        return [Attendance::query()->where('user_id', $user->id)->whereDate('work_date', $date)->firstOrFail(), false];
    }

    /** @param array{lat: float, lng: float, accuracy: float} $position */
    private function refuse(User $user, string $date, string $outcome, array $position, ?float $distance, int $radius, int $maxAccuracy, ?int $deviceId, ?string $ip): void
    {
        CheckinAttempt::query()->create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'work_date' => $date,
            'outcome' => $outcome,
            'lat' => $position['lat'],
            'lng' => $position['lng'],
            'accuracy_m' => $position['accuracy'],
            'distance_m' => $distance,
            'radius_m' => $radius,
            'max_accuracy_m' => $maxAccuracy,
            'ip' => $ip,
            'created_at' => Carbon::now(),
        ]);
    }

    private function notifyStaff(Permission $permission, StaffAlert $alert): void
    {
        $recipients = User::query()->holdingPermission($permission)->get();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $alert);
        }
    }
}
