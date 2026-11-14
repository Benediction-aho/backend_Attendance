<?php

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

it('uses the Accra timezone and English locale', function () {
    expect(config('app.timezone'))->toBe('Africa/Accra')
        ->and(config('app.locale'))->toBe('en')
        ->and(date_default_timezone_get())->toBe('Africa/Accra');
});

it('uses the database queue driver by default', function () {
    expect(config('queue.connections.database.driver'))->toBe('database');
});

it('trusts no proxy unless configured', function () {
    $request = Request::create('/api/health', 'GET', server: [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
    ]);

    config(['app.trusted_proxies' => '']);
    (new TrustProxies)->handle($request, fn ($r) => response('ok'));
    expect($request->ip())->toBe('10.0.0.1');

    config(['app.trusted_proxies' => '10.0.0.0/8']);
    (new TrustProxies)->handle($request, fn ($r) => response('ok'));
    expect($request->ip())->toBe('203.0.113.9');
});
