<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Web Push delivery (VAPID). Phase 3 placeholder: the notification is already stored in the
 * notification centre; phase 4 sends it to the user's push subscriptions.
 */
class SendWebPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @param array<string, mixed> $payload */
    public function __construct(public int $userId, public string $kind, public array $payload = []) {}

    public function handle(): void
    {
        Log::debug('Web push not configured yet', ['user_id' => $this->userId, 'kind' => $this->kind]);
    }
}
