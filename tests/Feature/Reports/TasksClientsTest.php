<?php

use App\Enums\Permission;
use App\Models\Client;
use App\Models\DailyReport;
use App\Models\Task;
use App\Models\User;
use App\Settings\SettingsService;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    at('2026-11-16 08:00'); // Monday
});

describe('tasks', function () {
    it('plans a task, marks it done with the server time, edits and deletes it', function () {
        $user = User::factory()->create();
        attend($user, '2026-11-16');
        Sanctum::actingAs($user);

        $id = $this->postJson('/api/me/tasks', ['title' => '  Call   the embassy ', 'work_date' => '2020-01-01'])->assertCreated()
            ->assertJsonPath('data.title', 'Call the embassy')->assertJsonPath('data.status', 'planned')
            ->assertJsonPath('data.work_date', '2026-11-16')->assertJsonPath('data.completed_at', null)->json('data.id');

        at('2026-11-16 10:15');
        $r = $this->patchJson("/api/me/tasks/$id", ['done' => true, 'completed_at' => '2020-01-01T00:00:00Z'])->assertOk()->assertJsonPath('data.status', 'done');
        expect($r->json('data.completed_at'))->toStartWith('2026-11-16T10:15:00');

        $this->patchJson("/api/me/tasks/$id", ['done' => false])->assertOk()->assertJsonPath('data.completed_at', null);
        $this->postJson('/api/me/tasks', ['title' => 'Written when done', 'done' => true])->assertCreated()->assertJsonPath('data.status', 'done');
        $this->getJson('/api/me/tasks')->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/me/tasks/$id")->assertNoContent();
        $this->getJson('/api/me/tasks')->assertJsonCount(1, 'data');
    });

    it('stays editable on later days (even after 17:00) and recomputes the daily report', function () {
        $user = User::factory()->create();
        attend($user, '2026-11-16');
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/me/tasks', ['title' => 'Visa file'])->json('data.id');
        at('2026-11-16 17:00');
        $this->artisan('reports:daily')->assertSuccessful();
        expect(DailyReport::query()->where('user_id', $user->id)->whereDate('work_date', '2026-11-16')->first()->tasks_done)->toBe(0);

        at('2026-11-17 09:00');
        attend($user, '2026-11-17');
        $this->patchJson("/api/me/tasks/$id", ['done' => true])->assertOk();
        $report = DailyReport::query()->where('user_id', $user->id)->whereDate('work_date', '2026-11-16')->first();
        expect($report->tasks_done)->toBe(1)->and($report->recomputed_at)->not->toBeNull();
        $this->getJson('/api/me/tasks?date=2026-11-16')->assertJsonPath('data.0.status', 'done');
    });

    it('requires today\'s check-in for writes but not for reading (P3-06)', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/me/tasks')->assertOk();
        $this->getJson('/api/me/clients')->assertOk();
        $this->getJson('/api/me/reports/daily')->assertOk();
        $this->postJson('/api/me/tasks', ['title' => 'x'])->assertForbidden()->assertJsonPath('code', 'checkin_required');
        $this->postJson('/api/me/clients', ['name' => 'A', 'service' => 'B', 'quantity' => 1])->assertForbidden()->assertJsonPath('code', 'checkin_required');
        $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->assertForbidden()->assertJsonPath('code', 'checkin_required');
    });

    it('never exposes or changes another employee\'s tasks', function () {
        $owner = User::factory()->create();
        $task = Task::query()->create(['user_id' => $owner->id, 'work_date' => '2026-11-16', 'title' => 'Secret', 'status' => 'planned']);
        $other = User::factory()->create();
        attend($other, '2026-11-16');
        Sanctum::actingAs($other);
        $this->patchJson("/api/me/tasks/{$task->id}", ['title' => 'hacked'])->assertNotFound();
        $this->deleteJson("/api/me/tasks/{$task->id}")->assertNotFound();
        $this->getJson('/api/me/tasks')->assertJsonCount(0, 'data');
        expect($task->fresh()->title)->toBe('Secret');
    });

    it('refuses admins (employees only) and guests', function () {
        $this->getJson('/api/me/tasks')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->getJson('/api/me/tasks')->assertForbidden()->assertJsonPath('code', 'employees_only');
        $this->postJson('/api/me/tasks', ['title' => 'x'])->assertForbidden();
    });

    it('validates the title and caps tasks per day (setting)', function () {
        app(SettingsService::class)->set(['tasks_max_per_day' => 2]);
        $user = User::factory()->create();
        attend($user, '2026-11-16');
        Sanctum::actingAs($user);
        $this->postJson('/api/me/tasks', ['title' => '   '])->assertUnprocessable();
        $this->postJson('/api/me/tasks', ['title' => str_repeat('a', 501)])->assertUnprocessable();
        $this->postJson('/api/me/tasks', ['title' => 'a'])->assertCreated();
        $this->postJson('/api/me/tasks', ['title' => 'b'])->assertCreated();
        $this->postJson('/api/me/tasks', ['title' => 'c'])->assertUnprocessable()->assertJsonPath('code', 'too_many_tasks');
    });
});

describe('clients', function () {
    it('adds clients with a normalized service key and lists the day with grouped services', function () {
        $user = User::factory()->create();
        attend($user, '2026-11-16');
        Sanctum::actingAs($user);
        $this->postJson('/api/me/clients', ['name' => 'Kofi', 'service' => 'Residence  Permit ', 'quantity' => 2])->assertCreated()
            ->assertJsonPath('data.service', 'Residence Permit')->assertJsonPath('data.service_key', 'residence permit');
        $this->postJson('/api/me/clients', ['name' => 'Ama', 'service' => 'residence permit', 'quantity' => 1])->assertCreated();
        $id = $this->postJson('/api/me/clients', ['name' => 'Yaw', 'service' => 'Visa', 'quantity' => 1])->json('data.id');

        $r = $this->getJson('/api/me/clients')->assertOk()->assertJsonCount(3, 'data');
        expect($r->json('services'))->toBe([
            ['service' => 'Residence Permit', 'service_key' => 'residence permit', 'quantity' => 3],
            ['service' => 'Visa', 'service_key' => 'visa', 'quantity' => 1],
        ]);

        $this->patchJson("/api/me/clients/$id", ['quantity' => 4, 'service' => 'VISA'])->assertOk()->assertJsonPath('data.service_key', 'visa');
        $this->deleteJson("/api/me/clients/$id")->assertNoContent();
        $this->getJson('/api/me/clients')->assertJsonCount(2, 'data');
    });

    it('suggests services already entered, one spelling per key, filtered by prefix text', function () {
        $a = User::factory()->create();
        foreach ([['Residence Permit', 3], ['residence permit', 1], ['Work Permit', 1]] as [$service, $n]) {
            for ($i = 0; $i < $n; $i++) {
                Client::query()->create(['user_id' => $a->id, 'work_date' => '2026-11-10', 'name' => 'X', 'service' => $service, 'service_key' => Client::serviceKey($service), 'quantity' => 1]);
            }
        }
        $b = User::factory()->create();
        Sanctum::actingAs($b);
        $r = $this->getJson('/api/clients/services?q=RESID')->assertOk();
        expect($r->json('data'))->toBe([['service' => 'Residence Permit', 'service_key' => 'residence permit']]);
        expect($this->getJson('/api/clients/services')->json('data'))->toHaveCount(2);
        expect($this->getJson('/api/clients/services?q=%25')->json('data'))->toHaveCount(0); // LIKE wildcards are literal
    });

    it('validates quantity bounds (setting), names and other users\' entries', function () {
        app(SettingsService::class)->set(['client_quantity_max' => 10]);
        $user = User::factory()->create();
        attend($user, '2026-11-16');
        Sanctum::actingAs($user);
        $this->postJson('/api/me/clients', ['name' => 'A', 'service' => 'B', 'quantity' => 0])->assertUnprocessable();
        $this->postJson('/api/me/clients', ['name' => 'A', 'service' => 'B', 'quantity' => 11])->assertUnprocessable();
        $this->postJson('/api/me/clients', ['name' => ' ', 'service' => 'B', 'quantity' => 1])->assertUnprocessable();
        $this->postJson('/api/me/clients', ['name' => 'A', 'service' => '  ', 'quantity' => 1])->assertUnprocessable();
        $foreign = Client::query()->create(['user_id' => User::factory()->create()->id, 'work_date' => '2026-11-16', 'name' => 'X', 'service' => 'S', 'service_key' => 's', 'quantity' => 1]);
        $this->patchJson("/api/me/clients/{$foreign->id}", ['quantity' => 2])->assertNotFound();
        $this->deleteJson("/api/me/clients/{$foreign->id}")->assertNotFound();
    });
});

describe('admin daily report view', function () {
    it('needs view_reports and filters task titles / client names by tier', function () {
        $emp = User::factory()->create(['last_name' => 'Ngari']);
        Task::query()->create(['user_id' => $emp->id, 'work_date' => '2026-11-16', 'title' => 'T1', 'status' => 'done', 'completed_at' => now()]);
        Client::query()->create(['user_id' => $emp->id, 'work_date' => '2026-11-16', 'name' => 'Kofi', 'service' => 'Visa', 'service_key' => 'visa', 'quantity' => 2]);

        Sanctum::actingAs(User::factory()->admin(Permission::ViewTasks, Permission::ViewClients)->create());
        $this->getJson('/api/admin/reports/daily?date=2026-11-16')->assertForbidden();

        Sanctum::actingAs(User::factory()->admin(Permission::ViewReports)->create());
        $r = $this->getJson('/api/admin/reports/daily?date=2026-11-16')->assertOk()
            ->assertJsonPath('data.0.clients_count', 1)->assertJsonPath('data.0.tasks_done', 1)->assertJsonPath('data.0.services.0.quantity', 2);
        expect($r->json('data.0'))->not->toHaveKey('tasks')->not->toHaveKey('clients');

        Sanctum::actingAs(User::factory()->admin(Permission::ViewReports, Permission::ViewTasks, Permission::ViewClients)->create());
        $this->getJson('/api/admin/reports/daily?date=2026-11-16')->assertJsonPath('data.0.tasks.0.title', 'T1')->assertJsonPath('data.0.clients.0.name', 'Kofi');

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->getJson("/api/admin/users/{$emp->id}/reports/weekly?date=2026-11-18")->assertOk()->assertJsonPath('data.week_start', '2026-11-16')->assertJsonPath('data.clients.0.name', 'Kofi');

        Sanctum::actingAs($emp);
        $this->getJson('/api/admin/reports/daily')->assertForbidden();
    });
});
