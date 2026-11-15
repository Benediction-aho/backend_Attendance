<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;

it('lower-cases PRIVATE_FILES_DISK in the configuration', function () {
    putenv('PRIVATE_FILES_DISK= S3 ');
    $config = require config_path('filesystems.php');
    putenv('PRIVATE_FILES_DISK');
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
