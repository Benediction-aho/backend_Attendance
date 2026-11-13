<?php

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

it('does not expose the Sanctum csrf-cookie route', function () {
    $response = $this->get('/sanctum/csrf-cookie');

    $response->assertNotFound();
    expect($response->headers->getCookies())->toBeEmpty();
});

it('never sets a cookie on API responses', function (string $method, string $uri) {
    $response = $this->json($method, $uri);

    expect($response->headers->getCookies())->toBeEmpty()
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
})->with([
    ['GET', '/api/health'],
    ['GET', '/api/does-not-exist'],
    ['POST', '/api/health'],
    ['POST', '/api/auth/login'],
    ['GET', '/api/me'],
]);

it('ignores a forged X-Forwarded-For when no proxy is trusted', function () {
    config(['app.trusted_proxies' => '']);
    Route::get('/api/_ip', fn (Request $r) => ['ip' => $r->ip()]);

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeader('X-Forwarded-For', '203.0.113.9')
        ->getJson('/api/_ip')
        ->assertJsonPath('ip', '198.51.100.7');
});

it('honours X-Forwarded-For only from a configured proxy', function () {
    config(['app.trusted_proxies' => '10.0.0.0/8']);
    Route::get('/api/_ip', fn (Request $r) => ['ip' => $r->ip()]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
        ->withHeader('X-Forwarded-For', '203.0.113.9')
        ->getJson('/api/_ip')
        ->assertJsonPath('ip', '203.0.113.9');

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeader('X-Forwarded-For', '203.0.113.9')
        ->getJson('/api/_ip')
        ->assertJsonPath('ip', '198.51.100.7');
});

it('sets no cookie on a successful login nor on authenticated requests', function () {
    $user = User::factory()->withPassword('Correct-Pass-1')->create(['email' => 'c@example.com']);

    $login = $this->postJson('/api/auth/login', loginPayload('c@example.com', 'Correct-Pass-1'))->assertOk();
    expect($login->headers->getCookies())->toBeEmpty();

    $me = $this->bearer($login->json('token'))->getJson('/api/me')->assertOk();
    expect($me->headers->getCookies())->toBeEmpty();
});

it('REL-05: behind "*" only the immediate proxy is trusted: the client IP is the entry it appended', function () {
    config(['app.trusted_proxies' => '*']);
    Route::get('/api/_ip', fn (Request $r) => ['ip' => $r->ip()]);

    // The client forged the left entry; the provider's proxy (10.1.2.3) appended the real address.
    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
        ->withHeader('X-Forwarded-For', '203.0.113.1, 198.51.100.44')
        ->getJson('/api/_ip')
        ->assertJsonPath('ip', '198.51.100.44');
});

it('REL-05: never builds a URL from X-Forwarded-Host or the Host header', function () {
    config(['app.trusted_proxies' => '*']);
    Route::get('/api/_url', fn () => ['url' => URL::temporarySignedRoute('files.show', now()->addMinutes(5), ['path' => 'a/b.pdf'])]);
    $root = rtrim((string) config('app.url'), '/');

    $url = $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
        ->withHeaders(['X-Forwarded-Host' => 'evil.example', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '8443', 'X-Forwarded-Prefix' => '/evil'])
        ->getJson('/api/_url')->json('url');

    expect($url)->toStartWith($root.'/api/files/a/b.pdf')->not->toContain('evil');
});

it('REL-06: refuses an oversized JSON body before parsing or rate limiting it', function () {
    config(['app.max_body_kb' => 64]);
    $big = json_encode(['email' => 'a@example.com', 'password' => str_repeat('x', 70 * 1024)]);

    $response = $this->call('POST', '/api/auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($big)], $big);
    $response->assertStatus(413)->assertJsonPath('code', 'payload_too_large');
    expect(LoginAttempt::query()->count())->toBe(0);

    // Without a Content-Length header the raw size is measured.
    $this->call('POST', '/api/auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $big)
        ->assertStatus(413);
    // A normal body still goes through.
    $this->postJson('/api/auth/login', loginPayload('nobody@example.com', 'wrong'))->assertStatus(401);
});

it('REL-06: leaves multipart uploads to their own per-route limits', function () {
    config(['app.max_body_kb' => 1]);
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('me.png', 200, 200);

    $response = $this->withToken(deviceToken($user))->call('POST', '/api/me/photo', [], [], ['photo' => $file],
        ['CONTENT_TYPE' => 'multipart/form-data; boundary=----x', 'CONTENT_LENGTH' => '5000000', 'HTTP_ACCEPT' => 'application/json']);

    expect($response->status())->not->toBe(413)->toBeLessThan(500);
});
