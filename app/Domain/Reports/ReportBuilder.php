<?php

namespace App\Domain\Reports;

use App\Domain\Attendance\WorkCalendar;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\DailyReport;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Builds daily, weekly and monthly report content from the current tasks and clients (SPECS 7).
 * Reports are always computed from up-to-date data, so later edits are reflected everywhere.
 */
class ReportBuilder
{
    public function __construct(private readonly WorkCalendar $calendar) {}

    /**
     * Groups client entries by normalized service: quantities summed, label = most used spelling
     * (ties: the first one entered). Sorted by quantity, then label.
     *
     * @param  iterable<Client>  $clients
     * @return list<array{service: string, service_key: string, quantity: int}>
     */
    public static function services(iterable $clients): array
    {
        /** @var array<string, array{quantity: int, labels: array<string, array{0: int, 1: int}>}> $groups */
        $groups = [];
        $order = 0;
        foreach ($clients as $client) {
            $key = $client->service_key;
            $groups[$key] ??= ['quantity' => 0, 'labels' => []];
            $groups[$key]['quantity'] += $client->quantity;
            $label = $client->service;
            $seen = $groups[$key]['labels'][$label] ?? [0, $order++];
            $groups[$key]['labels'][$label] = [$seen[0] + 1, $seen[1]];
        }
        $out = [];
        foreach ($groups as $key => $group) {
            $labels = $group['labels'];
            uasort($labels, fn (array $a, array $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
            $out[] = ['service' => (string) array_key_first($labels), 'service_key' => (string) $key, 'quantity' => $group['quantity']];
        }
        usort($out, fn (array $a, array $b) => [$b['quantity'], $a['service_key']] <=> [$a['quantity'], $b['service_key']]);

        return $out;
    }

    /**
     * @return array{work_date: string, tasks: list<array<string, mixed>>, tasks_planned: int, tasks_done: int, clients: list<array<string, mixed>>, clients_count: int, services: list<array{service: string, service_key: string, quantity: int}>}
     */
    public function daily(User $user, string $date): array
    {
        $tasks = Task::query()->where('user_id', $user->id)->whereDate('work_date', $date)->orderBy('id')->get();
        $clients = Client::query()->where('user_id', $user->id)->whereDate('work_date', $date)->orderBy('id')->get();

        return [
            'work_date' => $date,
            'tasks' => array_values($tasks->map(fn (Task $t) => $t->present())->all()),
            'tasks_planned' => $tasks->count(),
            'tasks_done' => $tasks->where('status', 'done')->count(),
            'clients' => array_values($clients->map(fn (Client $c) => $c->present())->all()),
            'clients_count' => $clients->count(),
            'services' => self::services($clients),
        ];
    }

    /** Stores (or refreshes) the daily snapshot of an employee. */
    public function snapshot(User $user, string $date, bool $recompute = false): DailyReport
    {
        $day = $this->daily($user, $date);
        $values = [
            'tasks_planned' => $day['tasks_planned'], 'tasks_done' => $day['tasks_done'],
            'clients_count' => $day['clients_count'], 'services' => $day['services'],
        ];
        $existing = DailyReport::query()->where('user_id', $user->id)->whereDate('work_date', $date)->first();
        if ($existing !== null) {
            $existing->update($values + ($recompute ? ['recomputed_at' => Carbon::now()] : []));

            return $existing;
        }
        DailyReport::query()->insertOrIgnore([
            ['user_id' => $user->id, 'work_date' => $date, 'generated_at' => Carbon::now()] + array_merge($values, ['services' => json_encode($day['services'])]),
        ]);

        return DailyReport::query()->where('user_id', $user->id)->whereDate('work_date', $date)->firstOrFail();
    }

    /** After an edit: a daily report already generated for that day is recomputed (SPECS 7 "Modifications"). */
    public function refreshIfGenerated(User $user, string $date): void
    {
        if (DailyReport::query()->where('user_id', $user->id)->whereDate('work_date', $date)->exists()) {
            $this->snapshot($user, $date, true);
        }
    }

    /** Monday (Y-m-d) of the ISO week containing $date. */
    public function weekStart(string $date): string
    {
        return Carbon::parse($date, $this->calendar->timezone())->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    /**
     * Weekly summary Monday → Friday of the week containing $date, built from the daily data.
     *
     * @return array<string, mixed>
     */
    public function weekly(User $user, string $date): array
    {
        $start = $this->weekStart($date);
        $end = Carbon::parse($start)->addDays(4)->toDateString();
        $days = [];
        for ($d = Carbon::parse($start); $d->toDateString() <= $end; $d->addDay()) {
            $day = $this->daily($user, $d->toDateString());
            $days[] = ['work_date' => $day['work_date'], 'tasks_planned' => $day['tasks_planned'], 'tasks_done' => $day['tasks_done'],
                'clients_count' => $day['clients_count'], 'services' => $day['services']];
        }
        $clients = Client::query()->where('user_id', $user->id)->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)
            ->orderBy('work_date')->orderBy('id')->get();
        $tasks = Task::query()->where('user_id', $user->id)->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->get();

        return [
            'week_start' => $start,
            'week_end' => $end,
            'days' => $days,
            'tasks_planned' => $tasks->count(),
            'tasks_done' => $tasks->where('status', 'done')->count(),
            'clients_count' => $clients->count(),
            'clients' => $clients->map(fn (Client $c) => ['work_date' => $c->work_date->toDateString(), 'name' => $c->name, 'service' => $c->service, 'quantity' => $c->quantity])->values()->all(),
            'services' => self::services($clients),
        ];
    }

    /**
     * Monthly rows (SPECS 7, template): one per employee, sorted by last name.
     * Employees: active employees, plus anyone else with lateness or clients in the period.
     *
     * @return list<array{user_id: int, employee: string, detail: string|null, late: int, clients: int, services: list<array{service: string, service_key: string, quantity: int}>}>
     */
    public function monthlyRows(string $start, string $end): array
    {
        $late = Attendance::query()->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->where('is_late', true)
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');
        $clients = Client::query()->whereDate('work_date', '>=', $start)->whereDate('work_date', '<=', $end)->orderBy('id')->get()->groupBy('user_id');
        $ids = collect($late->keys())->merge($clients->keys())->unique()->all();

        $users = User::query()->where('role', Role::Employee->value)
            ->where(fn ($q) => $q->where('status', 'active')->orWhereIn('id', $ids))
            ->orderByRaw('upper(last_name), upper(first_name), id')->get();

        // UX5-02: an account that joined after the period (catch-up run) is not listed unless it has data.
        $users = $users->filter(fn (User $u) => in_array($u->id, $ids, false) || $this->calendar->wasMemberOn($u, $end))->values();
        // UX5-08: same-name employees are told apart by their job title, then by the email local part.
        $names = $users->countBy(fn (User $u) => mb_strtoupper($u->last_name.' '.$u->first_name));
        $withTitle = $users->countBy(fn (User $u) => mb_strtoupper($u->last_name.' '.$u->first_name).'|'.$u->job_title);

        $rows = [];
        foreach ($users as $user) {
            $key = mb_strtoupper($user->last_name.' '.$user->first_name);
            $suffix = null;
            if ($names[$key] > 1) {
                $title = trim((string) $user->job_title);
                $suffix = $title !== '' && $withTitle[$key.'|'.$user->job_title] === 1
                    ? $title
                    : ($title !== '' ? $title.', ' : '').strstr($user->email, '@', true);
            }
            $own = $clients->get($user->id) ?? collect();
            $rows[] = [
                'user_id' => $user->id,
                'employee' => mb_strtoupper($user->last_name).' '.$user->first_name,
                'detail' => $suffix,
                'late' => (int) ($late[$user->id] ?? 0),
                'clients' => $own->count(),
                'services' => self::services($own),
            ];
        }

        return $rows;
    }
}
