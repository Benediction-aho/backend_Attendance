<?php

namespace App\Services\Push;

use App\Models\PushSubscription;

interface PushTransport
{
    public function isConfigured(): bool;

    public function send(PushSubscription $subscription, string $payload, int $ttlSeconds): PushResult;
}
