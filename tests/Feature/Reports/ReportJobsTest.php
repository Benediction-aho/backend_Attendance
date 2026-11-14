<?php

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\Client;
use App\Models\DailyReport;
use App\Models\Leave;
use App\Models\ScheduledRun;
use App\Models\Task;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    at('2026-11-09 08:00'); // accounts exist from the previous week
});

describe('daily report job', function () {
    it('runs at 17:00, once, for expected employees and anyone with data', function () {
        $a = User::factory()->create();
        $onLeave = User::factory()->create();
        Leave::query()->create(['user_id' => $onLeave->id, 'start_date' => '2026-11-16', 'end_date' => '2026-11-16']);
        Client::query()->create(['user_id' => $onLeave->id, 'work_date' => '2026-11-16', 'name' => 'K', 'service' => 'Visa', 'service_key' => 'visa', 'quantity' => 3]);
        $leaver = User::factory()->status(UserStatus::Blocked)->create();
        Task::query()->create(['user_id' => $a->id, 'work_date' => '2026-11-16', 'title' => 'x', 'status' => 'done', 'completed_at' => now()]);
        Task::query()->create(['user_id' => $a->id, 'work_date' => '2026-11-16', 'title' => 'y', 'status' => 'planned']);
        User::factory()->admin(Permission::ViewReports)->create();

        at('2026-11-16 16:59:59');
        $this->artisan('reports:daily')->assertSuccessful();
        expect(DailyReport::query()->whereDate('work_date', '2026-11-16')->count())->toBe(0);

        at('2026-11-16 17:00');
        $this->artisan('reports:daily')->assertSuccessful();
        $rows = DailyReport::query()->whereDate('work_date', '2026-11-16')->get()->keyBy('user_id');
        expect($rows->keys()->sort()->values()->all())->toBe(collect([$a->id, $onLeave->id])->sort()->values()->all());
        expect($rows[$a->id]->tasks_planned)->toBe(2)->and($rows[$a->id]->tasks_done)->toBe(1)
            ->and($rows[$onLeave->id]->clients_count)->toBe(1)->and($rows[$onLeave->id]->services[0]['quantity'])->toBe(3);
        expect($rows->has($leaver->id))->toBeFalse();

        $this->artisan('reports:daily')->assertSuccessful();
        expect(ScheduledRun::query()->where('run_key', 'daily_report:2026-11-16')->count())->toBe(1);
    });

    it('catches up a missed 17:00 run the next day', function () {
        $a = User::factory()->create();
        at('2026-11-17 07:00');
        $this->artisan('reports:daily')->assertSuccessful();
        expect(DailyReport::query()->where('user_id', $a->id)->whereDate('work_date', '2026-11-16')->exists())->toBeTrue()
            ->and(DailyReport::query()->whereDate('work_date', '2026-11-17')->exists())->toBeFalse();
    });

    it('does not create reports for days before the account existed', function () {
        at('2026-11-16 12:00');
        $new = User::factory()->create();
        at('2026-11-16 17:00');
        $this->artisan('reports:daily')->assertSuccessful();
        expect(DailyReport::query()->where('user_id', $new->id)->pluck('work_date')->map->toDateString()->all())->toBe(['2026-11-16']);
    });
});

describe('weekly summary', function () {
    it('covers Monday to Friday with the clients recap', function () {
        $u = User::factory()->create();
        foreach (['2026-11-15' => 5, '2026-11-16' => 1, '2026-11-18' => 2, '2026-11-20' => 1, '2026-11-21' => 7] as $date => $qty) {
            Client::query()->create(['user_id' => $u->id, 'work_date' => $date, 'name' => "C$date", 'service' => 'Residence permit', 'service_key' => 'residence permit', 'quantity' => $qty]);
        }
        Task::query()->create(['user_id' => $u->id, 'work_date' => '2026-11-17', 'title' => 'x', 'status' => 'done', 'completed_at' => now()]);
        at('2026-11-19 10:00');
        Sanctum::actingAs($u);
        $r = $this->getJson('/api/me/reports/weekly')->assertOk()
            ->assertJsonPath('data.week_start', '2026-11-16')->assertJsonPath('data.week_end', '2026-11-20')
            ->assertJsonPath('data.clients_count', 3)->assertJsonPath('data.tasks_done', 1)
            ->assertJsonPath('data.services.0.quantity', 4)->assertJsonCount(5, 'data.days');
        expect(collect($r->json('data.clients'))->pluck('work_date')->all())->toBe(['2026-11-16', '2026-11-18', '2026-11-20']);
        $this->getJson('/api/me/reports/daily?date=2026-11-18')->assertJsonPath('data.clients_count', 1)->assertJsonPath('data.generated_at', null);
    });
});
