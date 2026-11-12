<?php

use App\Enums\Permission;
use App\Mail\EmailChangeConfirmMail;
use App\Mail\SecurityAlertMail;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-11-12 10:00', 'Africa/Accra'));
    $this->user = User::factory()->withPassword('Current-pass-1')->create(['email' => 'ama@example.com']);
    $this->token = deviceToken($this->user);
});

it('updates the profile fields and logs the changed fields', function () {
    $this->bearer($this->token)->patchJson('/api/me', [
        'first_name' => ' Ama ', 'birth_date' => '1998-04-12', 'address' => 'East Legon, Accra',
        'phone' => '+233 24 123 4567', 'whatsapp' => '+233241234567',
    ])->assertOk()
        ->assertJsonPath('data.first_name', 'Ama')
        ->assertJsonPath('data.birth_date', '1998-04-12')
        ->assertJsonPath('data.phone', '+233 24 123 4567');

    expect(ActivityLog::query()->where('event', 'profile.updated')->first()->context['fields'])
        ->toContain('birth_date', 'phone');
});

it('ignores fields a user may not change (role, status, email)', function () {
    $this->bearer($this->token)->patchJson('/api/me', ['role' => 'super_admin', 'status' => 'active', 'email' => 'x@example.com'])->assertOk();

    expect($this->user->refresh()->role->value)->toBe('employee')->and($this->user->email)->toBe('ama@example.com');
});

it('validates profile fields', function (array $payload, string $field) {
    $this->bearer($this->token)->patchJson('/api/me', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['birth_date' => '2030-01-01'], 'birth_date'],
    [['birth_date' => '12/04/1998'], 'birth_date'],
    [['phone' => 'call me'], 'phone'],
    [['first_name' => ''], 'first_name'],
    [['address' => str_repeat('a', 300)], 'address'],
]);

it('uploads a photo to private storage with a server-generated name and a temporary signed URL', function () {
    $response = $this->bearer($this->token)->postJson('/api/me/photo', ['photo' => UploadedFile::fake()->image('my selfie.png', 400, 400)])->assertOk();

    $path = $this->user->refresh()->photo_path;
    expect($path)->toStartWith("photos/{$this->user->id}/")->not->toContain('selfie')->toEndWith('.png');
    Storage::disk('local')->assertExists($path);

    $url = $response->json('data.photo_url');
    expect($url)->toContain('/api/files/')->toContain('signature=')->toContain('expires=');

    $this->guest()->get($url)->assertOk();
    $this->get(str_replace('signature=', 'signature=x', $url))->assertNotFound();
    $this->get('/api/files/'.$path)->assertNotFound();

    Carbon::setTestNow(Carbon::now()->addMinutes(11));
    $this->get($url)->assertNotFound();
});

it('replaces the old photo file and can remove it', function () {
    $this->bearer($this->token)->postJson('/api/me/photo', ['photo' => UploadedFile::fake()->image('a.jpg')])->assertOk();
    $first = $this->user->refresh()->photo_path;
    $this->bearer($this->token)->postJson('/api/me/photo', ['photo' => UploadedFile::fake()->image('b.jpg')])->assertOk();
    Storage::disk('local')->assertMissing($first);

    $this->bearer($this->token)->deleteJson('/api/me/photo')->assertOk()->assertJsonPath('data.photo_url', null);
    expect($this->user->refresh()->photo_path)->toBeNull();
});

it('refuses non-image and oversized uploads', function () {
    $this->bearer($this->token)->postJson('/api/me/photo', ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertUnprocessable();
    $this->bearer($this->token)->postJson('/api/me/photo', ['photo' => UploadedFile::fake()->create('evil.php.jpg', 10, 'text/x-php')])->assertUnprocessable();
    $this->bearer($this->token)->postJson('/api/me/photo', ['photo' => UploadedFile::fake()->image('big.jpg')->size(6000)])->assertUnprocessable();
});

it('changes the email after confirmation from the new address, with an alert to the old one', function () {
    $this->bearer($this->token)->postJson('/api/me/email', ['email' => 'Ama.New@Example.com', 'current_password' => 'Current-pass-1'])->assertStatus(202);

    $token = null;
    Mail::assertQueued(EmailChangeConfirmMail::class, function ($m) use (&$token) {
        $token = $m->token;

        return $m->hasTo('ama.new@example.com');
    });
    Mail::assertQueued(SecurityAlertMail::class, fn ($m) => $m->hasTo('ama@example.com'));
    expect($this->user->refresh()->email)->toBe('ama@example.com');

    $this->guest()->postJson('/api/auth/email-change/confirm', ['token' => $token])->assertOk()->assertJsonPath('status', 'applied');
    expect($this->user->refresh()->email)->toBe('ama.new@example.com');
    $this->postJson('/api/auth/email-change/confirm', ['token' => $token])->assertStatus(422);

    $this->postJson('/api/auth/login', loginPayload('ama.new@example.com', 'Current-pass-1'))->assertOk();
});

it('requires the current password and an available address', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->bearer($this->token)->postJson('/api/me/email', ['email' => 'new@example.com', 'current_password' => 'wrong'])->assertStatus(422)->assertJsonPath('code', 'wrong_password');
    $this->bearer($this->token)->postJson('/api/me/email', ['email' => 'taken@example.com', 'current_password' => 'Current-pass-1'])->assertStatus(422)->assertJsonPath('code', 'email_unavailable');
    $this->bearer($this->token)->postJson('/api/me/email', ['email' => 'nope', 'current_password' => 'Current-pass-1'])->assertUnprocessable();
});

it('expires email change links', function () {
    $this->bearer($this->token)->postJson('/api/me/email', ['email' => 'new@example.com', 'current_password' => 'Current-pass-1'])->assertStatus(202);
    $token = null;
    Mail::assertQueued(EmailChangeConfirmMail::class, function ($m) use (&$token) {
        $token = $m->token;

        return true;
    });
    Carbon::setTestNow(Carbon::now()->addMinutes(61));

    $this->postJson('/api/auth/email-change/confirm', ['token' => $token])->assertStatus(422);
});

it('requires both the old and the new address for a super admin', function () {
    $sa = User::factory()->superAdmin()->create(['email' => 'boss@example.com']);
    $this->bearer(deviceToken($sa, 'device-sa-0000000000001'))->postJson('/api/me/email', ['email' => 'boss2@example.com'])->assertStatus(202);

    $tokens = [];
    Mail::assertQueued(EmailChangeConfirmMail::class, function ($m) use (&$tokens) {
        $tokens[$m->toOldAddress ? 'old' : 'new'] = $m->token;

        return true;
    });

    $this->guest()->postJson('/api/auth/email-change/confirm', ['token' => $tokens['new']])->assertOk()->assertJsonPath('status', 'pending');
    expect($sa->refresh()->email)->toBe('boss@example.com');
    $this->postJson('/api/auth/email-change/confirm', ['token' => $tokens['old']])->assertOk()->assertJsonPath('status', 'applied');
    expect($sa->refresh()->email)->toBe('boss2@example.com');
});

it('shows personal data only to the user and to account managers', function () {
    $this->user->update(['phone' => '+233200000000']);
    $peer = User::factory()->create();
    $manager = User::factory()->admin(Permission::BlockUsers)->create();
    $reportsOnly = User::factory()->admin(Permission::ViewReports)->create();

    $this->bearer(deviceToken($peer, 'device-peer-000000001'))->getJson("/api/admin/users/{$this->user->id}")->assertForbidden();
    $this->bearer(deviceToken($reportsOnly, 'device-rep-0000000001'))->getJson("/api/admin/users/{$this->user->id}")->assertForbidden();
    $this->bearer(deviceToken($manager, 'device-man-0000000001'))->getJson("/api/admin/users/{$this->user->id}")
        ->assertOk()->assertJsonPath('data.phone', '+233200000000')->assertJsonMissingPath('data.password');
})->skip(fn () => ! Route::has('admin.users.show'), 'admin user endpoints come with item 8');
