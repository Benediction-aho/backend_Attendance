<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Production bug (2026-11-14): the PWA and the API are on different sites, and every API response said
 * "Cross-Origin-Resource-Policy: same-site", so browsers refused profile photos in <img> (local private disk).
 */

it('serves signed private files with a cross-origin resource policy, keeping the other protections', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $url = $this->bearer(deviceToken($user))->post('/api/me/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 200, 200)])
        ->assertOk()->json('data.photo_url');
    expect($url)->toContain('/api/files/');

    $this->guest()->get($url)->assertOk()
        ->assertHeader('Cross-Origin-Resource-Policy', 'cross-origin')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'; base-uri 'none'")
        ->assertHeader('Content-Disposition', 'attachment; filename="file.jpg"')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('keeps same-site on every other response, refused file links included', function (string $method, string $uri, int $status) {
    $this->json($method, $uri)->assertStatus($status)->assertHeader('Cross-Origin-Resource-Policy', 'same-site');
})->with([
    'health' => ['GET', '/api/health', 200],
    'guest api route' => ['GET', '/api/me', 401],
    'login failure' => ['POST', '/api/auth/login', 422],
    'unsigned file link' => ['GET', '/api/files/photos/1/x.jpg', 404],
    'unknown route' => ['GET', '/api/nothing-here', 404],
]);

it('keeps same-site on authenticated API responses', function () {
    $this->bearer(deviceToken(User::factory()->create()))->getJson('/api/me')->assertOk()
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-site');
});
