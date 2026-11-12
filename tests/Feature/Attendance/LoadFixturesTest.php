<?php

use App\Models\User;

it('creates load-test employees with tokens outside production', function () {
    $out = 'storage/framework/testing/load-tokens-test.json';
    $this->artisan('attendance:load-fixtures', ['--count' => 3, '--output' => $out])->assertSuccessful();
    expect(User::query()->where('email', 'like', '%@loadtest.invalid')->count())->toBe(3)
        ->and(json_decode((string) file_get_contents(base_path($out)), true))->toHaveCount(3);
    @unlink(base_path($out));
});

it('is refused in production', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('attendance:load-fixtures', ['--count' => 1])->assertFailed();
    expect(User::query()->count())->toBe(0);
});
