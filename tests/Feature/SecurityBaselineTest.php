<?php

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('does not send technology-revealing headers', function () {
    $response = $this->getJson('/api/health');

    $response->assertHeaderMissing('X-Powered-By');
    $response->assertHeaderMissing('Server');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'no-referrer');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('returns a JSON 404 for unknown API routes', function () {
    $this->get('/api/does-not-exist')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['message' => 'Not found.']);
});

it('returns a JSON 404 for unknown non-API paths too', function () {
    $this->get('/')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $this->get('/.env')->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});

it('returns a JSON 405 for a wrong method', function () {
    $this->postJson('/api/health')->assertStatus(405)->assertExactJson(['message' => 'Method not allowed.']);
});

it('hides exception details when debug is off', function () {
    config(['app.debug' => false]);
    Route::get('/api/_boom', fn () => throw new RuntimeException('secret internal detail /var/www'));

    $response = $this->get('/api/_boom');

    $response->assertStatus(500)
        ->assertExactJson(['message' => 'Something went wrong. Please try again later.']);
    expect($response->getContent())
        ->not->toContain('secret internal detail')
        ->not->toContain('RuntimeException')
        ->not->toContain('trace');
});

it('shows exception details only when debug is on', function () {
    config(['app.debug' => true]);
    Route::get('/api/_boom', fn () => throw new RuntimeException('detail'));

    $this->get('/api/_boom')->assertStatus(500)->assertJsonPath('debug.message', 'detail');
});

it('renders validation errors as JSON with field errors', function () {
    Route::post('/api/_validate', fn (Request $r) => $r->validate(['email' => 'required|email']));

    $this->post('/api/_validate', [])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The given data was invalid.')
        ->assertJsonValidationErrors('email');
});

it('uses neutral session and cache names', function () {
    expect(config('session.cookie'))->not->toContain('laravel')
        ->and(config('cache.prefix'))->not->toContain('laravel');
});

it('honours configured trusted proxies in the HTTP stack', function () {
    config(['app.trusted_proxies' => '*']);
    Route::get('/api/_ip', fn (Request $r) => ['ip' => $r->ip()]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeader('X-Forwarded-For', '203.0.113.9')
        ->getJson('/api/_ip')
        ->assertJsonPath('ip', '203.0.113.9');
});

it('answers guests with a JSON 401 even without an Accept header, and logs nothing (P1-04)', function () {
    $this->get('/api/me')->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');
    $this->get('/api/admin/users', ['Authorization' => 'Bearer 1|forged'])->assertStatus(401);
    expect(ActivityLog::query()->where('event', 'server.error')->count())->toBe(0);
});
