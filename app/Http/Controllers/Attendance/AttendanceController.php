<?php

namespace App\Http\Controllers\Attendance;

use App\Domain\Attendance\AttendancePresenter;
use App\Domain\Attendance\AttendanceService;
use App\Domain\Attendance\DayStatus;
use App\Domain\Attendance\WorkCalendar;
use App\Domain\FaceId\WebAuthnService;
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
        private readonly WebAuthnService $webauthn,
    ) {}

    /** Today's status, with the server time so the PWA can synchronise its clock. */
    public function today(Request $request): JsonResponse
    {
        return response()->json($this->todayPayload($request, $this->user($request)));
    }

    /**
     * Same body as GET /attendance/today. Also returned as `today` by confirm and check-out so the
     * client can render the new state immediately (UX34-02).
     *
     * @return array<string, mixed>
     */
    private function todayPayload(Request $request, User $user): array
    {
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

        return [
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
                'checkin_opens_at' => $this->settings->string('checkin_opens_at'),
                'checkin_open' => $now->gte($this->calendar->at($date, $this->settings->string('checkin_opens_at'))),
                'end_of_day' => $this->settings->string('end_of_day_time'),
                'early_checkout_reason_required' => $this->settings->bool('early_checkout_reason_required'),
                'faceid_required' => $this->settings->bool('faceid_required_for_checkin'),
            ],
            // ready | not_registered | pending_approval | other_device (for this session's device).
            'faceid_state' => $this->webauthn->state($user, $this->deviceId($request)),
        ];
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
        $deviceId = $this->deviceId($request);
        $faceIdRequired = $this->settings->bool('faceid_required_for_checkin');
        if ($faceIdRequired) {
            $this->ensureFaceIdReady($this->webauthn->state($user, $deviceId));
        }

        $intent = $this->service->createIntent($user, $data['type'], $position, $data['type'] === Attendance::TYPE_MISSION ? ($data['reason'] ?? null) : null, $deviceId, $request->ip());
        $faceIdOptions = $this->webauthn->checkinOptions($user, $intent);

        return response()->json([
            'intent_id' => $intent->id,
            'type' => $intent->type,
            'expires_at' => $intent->expires_at->toIso8601String(),
            'distance_m' => $intent->distance_m !== null ? round((float) $intent->distance_m, 1) : null,
            'faceid_required' => $faceIdRequired,
            // WebAuthn request options for navigator.credentials.get() (challenge bound to this intent).
            'faceid_options' => $faceIdOptions,
        ], 201);
    }

    /** Step 2: confirmation (Face ID assertion from phase 4). Idempotent for a double submit. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'intent_id' => ['required', 'uuid'],
            'assertion' => ['nullable', 'array'],
        ]);
        $user = $this->user($request);
        [$attendance, $created] = $this->service->confirm($user, $data['intent_id'], $data['assertion'] ?? null, $this->deviceId($request));

        return response()->json([
            'data' => $this->presenter->forEmployee($attendance),
            'created' => $created,
            'today' => $this->todayPayload($request, $user),
        ], $created ? 201 : 200);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $user = $this->user($request);
        $attendance = $this->service->checkOut($user, $this->position($data), $data['reason'] ?? null);

        return response()->json(['data' => $this->presenter->forEmployee($attendance), 'today' => $this->todayPayload($request, $user)]);
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

    private function ensureFaceIdReady(string $state): void
    {
        match ($state) {
            'ready' => null,
            'pending_approval' => throw new ApiError(403, 'Your new Face ID device is waiting for approval by an administrator.', 'faceid_pending_approval'),
            'other_device' => throw new ApiError(403, 'Face ID is registered on another device. Register this device and ask an administrator to approve it.', 'faceid_other_device'),
            default => throw new ApiError(403, 'Please register Face ID on this device before checking in.', 'faceid_not_registered'),
        };
    }

    private function deviceId(Request $request): ?int
    {
        $deviceId = $request->attributes->get('device_id');

        return is_int($deviceId) ? $deviceId : null;
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
