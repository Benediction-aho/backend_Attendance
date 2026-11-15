<?php

namespace App\Http\Controllers\Attendance;

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Models\Holiday;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\ActivityLogger;
use App\Support\SignupText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/** Public holidays (SPECS 6 bis): read by everyone signed in, managed by super admins / "manage_leaves" admins. */
class HolidayController
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']]);
        $holidays = Holiday::query()
            ->when($data['year'] ?? null, fn ($q, $y) => $q->whereYear('date', (int) $y))
            ->orderBy('date')->get();

        return response()->json(['data' => $holidays->map(fn (Holiday $h) => $this->row($h))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage-holidays');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'unique:holidays,date'],
            'name' => ['required', 'string', 'max:120'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $holiday = Holiday::query()->create(['date' => $data['date'], 'name' => trim($data['name']), 'created_by' => $actor->id]);
        $this->logger->log('holiday.created', 'Public holiday added', LogType::Action, LogLevel::Info, null, $actor, ['date' => $data['date'], 'name' => $holiday->name]);
        $this->alertSuperAdmins($actor, 'added', $holiday);

        return response()->json(['data' => $this->row($holiday)], 201);
    }

    public function destroy(Request $request, Holiday $holiday): JsonResponse
    {
        Gate::authorize('manage-holidays');
        /** @var User $actor */
        $actor = $request->user();
        $holiday->delete();
        $this->logger->log('holiday.deleted', 'Public holiday removed', LogType::Action, LogLevel::Info, null, $actor, ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);
        $this->alertSuperAdmins($actor, 'removed', $holiday);

        return response()->json(null, 204);
    }

    /**
     * CHG-02: a holiday removes everyone's obligations for the day (admins' own included). A change made by an
     * admin is announced to every active super admin (in-app + e-mail), like configuration changes.
     */
    private function alertSuperAdmins(User $actor, string $verb, Holiday $holiday): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }
        $date = $holiday->date->toDateString();
        Notification::send(User::query()->activeSuperAdmins()->get(), new StaffAlert(
            'holiday_changed',
            'Public holiday '.$verb.' by an admin',
            "{$actor->fullName()} {$verb} the public holiday of {$date}: ".SignupText::neutralize($holiday->name).'.',
            ['date' => $date, 'action' => $verb, 'actor_id' => $actor->id],
            path: '/admin/settings',
        ));
    }

    /** @return array<string, mixed> */
    private function row(Holiday $h): array
    {
        return ['id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name];
    }
}
