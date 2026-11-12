<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/** Prints a new VAPID key pair to put in the host's secrets (never in the repository). */
class GenerateVapidKeys extends Command
{
    protected $signature = 'attendance:vapid-keys';

    protected $description = 'Generate a VAPID key pair for Web Push';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->warn('Store the private key only in the host secrets. Changing the keys invalidates every existing subscription.');

        return self::SUCCESS;
    }
}
