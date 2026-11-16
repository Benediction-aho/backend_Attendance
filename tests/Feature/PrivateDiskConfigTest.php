<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;

it('lower-cases PRIVATE_FILES_DISK in the configuration', function () {
    // env() reads $_ENV / $_SERVER before getenv(): set all three (CI copies .env.example, which sets "local").
    $saved = [$_ENV['PRIVATE_FILES_DISK'] ?? null, $_SERVER['PRIVATE_FILES_DISK'] ?? null, getenv('PRIVATE_FILES_DISK')];
    $_ENV['PRIVATE_FILES_DISK'] = $_SERVER['PRIVATE_FILES_DISK'] = ' S3 ';
    putenv('PRIVATE_FILES_DISK= S3 ');
    try {
        $config = require config_path('filesystems.php');
    } finally {
        foreach ([0 => '_ENV', 1 => '_SERVER'] as $i => $bag) {
            if ($saved[$i] === null) {
                unset($GLOBALS[$bag]['PRIVATE_FILES_DISK']);
            } else {
                $GLOBALS[$bag]['PRIVATE_FILES_DISK'] = $saved[$i];
            }
        }
        putenv($saved[2] === false ? 'PRIVATE_FILES_DISK' : 'PRIVATE_FILES_DISK='.$saved[2]);
    }
    expect($config['private_disk'])->toBe('s3');
});

it('logs a clear error at boot when the private disk is not configured, and nothing when it is', function () {
    Log::spy();
    $provider = new AppServiceProvider(app());
    expect($provider->checkPrivateDisk())->toBeTrue();
    Log::shouldNotHaveReceived('error');

    config(['filesystems.private_disk' => 'nope']);
    expect($provider->checkPrivateDisk())->toBeFalse();
    Log::shouldHaveReceived('error')->once()->withArgs(fn ($m) => str_contains($m, 'PRIVATE_FILES_DISK="nope" is not a configured disk'));
});
