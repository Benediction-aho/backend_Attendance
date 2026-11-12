<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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
