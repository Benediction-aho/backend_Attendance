<?php

namespace App\Http\Controllers\Attendance;

use App\Domain\Attendance\AttendancePresenter;
use App\Domain\Attendance\AttendanceService;
use App\Domain\Attendance\DayStatus;
use App\Domain\Attendance\WorkCalendar;
use App\Exceptions\ApiError;
use App\Models\Attendance;
use App\Models\User;
use App\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Employee check-in / check-out (SPECS 6). Every time and date comes from the server. */
class AttendanceController
{
    public function __construct(
        private readonly AttendanceService $service,
        private readonly WorkCalendar $calendar,
        private readonly SettingsService $settings,
        private readonly AttendancePresenter $presenter,
    ) {}

    /** Today's status, with the server time so the PWA can synchronise its clock. */
    public function today(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $now = $this->calendar->now();
        $date = $this->calendar->workDate($now);
        $status = $this->calendar->dayStatus($user, $date);
        $attendance = $this->service->forDate($user, $date);
        $holiday = $status === DayStatus::Holiday ? $this->calendar->holiday($date) : null;

        $available = [];
        if ($attendance === null) {
            $available = [Attendance::TYPE_GPS, Attendance::TYPE_MISSION];
            if ($status === DayStatus::Remote) {
                $available[] = Attendance::TYPE_REMOTE;
            }
        }

        return response()->json([
            'server_time' => $now->toIso8601String(),
            'server_timestamp_ms' => (int) $now->valueOf(),
            'timezone' => $this->calendar->timezone(),
            'work_date' => $date,
            'day_status' => $status->value,
            'expected' => $status->isExpected(),
            'holiday_name' => $holiday?->name,
            'checked_in' => $attendance !== null,
            'checked_out' => $attendance?->check_out_at !== null,
            'attendance' => $attendance !== null ? $this->presenter->forEmployee($attendance) : null,
            'available_check_ins' => $available,
            'rules' => [
                'radius_m' => $this->settings->int('checkin_radius_m'),
                'max_accuracy_m' => $this->settings->int('checkin_max_accuracy_m'),
                'late_after' => $this->settings->string('late_after'),
                'end_of_day' => $this->settings->string('end_of_day_time'),
                'early_checkout_reason_required' => $this->settings->bool('early_checkout_reason_required'),
                'faceid_required' => $this->settings->bool('faceid_required_for_checkin'),
            ],
        ]);
    }

    /** Step 1: position check (gps) or eligibility (remote, mission). */
    public function intent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([Attendance::TYPE_GPS, Attendance::TYPE_MISSION, Attendance::TYPE_REMOTE])],
            'latitude' => ['required_if:type,gps', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['required_if:type,gps', 'nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['required_if:type,gps', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'reason' => ['required_if:type,mission', 'nullable', 'string', 'max:500'],
        ]);
        $user = $this->user($request);
        $position = $this->position($data);
        $deviceId = $request->attributes->get('device_id');

        $intent = $this->service->createIntent($user, $data['type'], $position, $data['type'] === Attendance::TYPE_MISSION ? ($data['reason'] ?? null) : null, is_int($deviceId) ? $deviceId : null, $request->ip());

        return response()->json([
            'intent_id' => $intent->id,
            'type' => $intent->type,
            'expires_at' => $intent->expires_at->toIso8601String(),
            'distance_m' => $intent->distance_m !== null ? round((float) $intent->distance_m, 1) : null,
            'faceid_required' => $this->settings->bool('faceid_required_for_checkin'),
            // Phase 4: WebAuthn request options (challenge) are returned here.
            'faceid_options' => null,
        ], 201);
    }

    /** Step 2: confirmation (Face ID assertion from phase 4). Idempotent for a double submit. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'intent_id' => ['required', 'uuid'],
            'assertion' => ['nullable', 'array'],
        ]);
        $deviceId = $request->attributes->get('device_id');
        [$attendance, $created] = $this->service->confirm($this->user($request), $data['intent_id'], $data['assertion'] ?? null, is_int($deviceId) ? $deviceId : null);

        return response()->json(['data' => $this->presenter->forEmployee($attendance), 'created' => $created], $created ? 201 : 200);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $attendance = $this->service->checkOut($this->user($request), $this->position($data), $data['reason'] ?? null);

        return response()->json(['data' => $this->presenter->forEmployee($attendance)]);
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = Attendance::query()->where('user_id', $this->user($request)->id)
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '<=', $v))
            ->orderByDesc('work_date')->paginate((int) ($data['per_page'] ?? 30));

        return response()->json([
            'data' => collect($page->items())->map(fn (Attendance $a) => $this->presenter->forEmployee($a)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{lat: float, lng: float, accuracy: float}|null
     */
    private function position(array $data): ?array
    {
        if (! isset($data['latitude'], $data['longitude'])) {
            return null;
        }

        return ['lat' => (float) $data['latitude'], 'lng' => (float) $data['longitude'], 'accuracy' => (float) ($data['accuracy'] ?? 0)];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new ApiError(401, 'Unauthenticated.');
        }

        return $user;
    }
}
