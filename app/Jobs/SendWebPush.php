<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\PushResult;
use App\Services\Push\PushTransport;
use App\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Web Push copy of an in-app notification (the in-app record is always stored first).
 * Best effort, never retried (a retry would duplicate the pushes already delivered).
 * Expired subscriptions (404/410) are deleted.
 */
class SendWebPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @param array<string, mixed> $payload title, body, path (+ optional context) */
    public function __construct(public int $userId, public string $kind, public array $payload = []) {}

    public function handle(PushTransport $transport, SettingsService $settings): void
    {
        if (! $transport->isConfigured() || ! $settings->bool('push_enabled')) {
            Log::debug('Web push skipped (not configured or disabled)', ['user_id' => $this->userId, 'kind' => $this->kind]);

            return;
        }
        $user = User::query()->find($this->userId);
        if ($user === null || ! $user->isActive()) {
            return;
        }

        // Minimal payload: what the service worker needs to display the notification and open the right page.
        $body = (string) json_encode([
            'kind' => $this->kind,
            'title' => (string) ($this->payload['title'] ?? 'Assistance Ghana Attendance'),
            'body' => mb_substr((string) ($this->payload['body'] ?? ''), 0, 300),
            'path' => is_string($this->payload['path'] ?? null) && str_starts_with($this->payload['path'], '/') ? $this->payload['path'] : '/',
            'tag' => $this->kind,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $ttl = $settings->int('push_ttl_seconds');

        PushSubscription::query()->where('user_id', $user->id)->orderBy('id')->each(function (PushSubscription $subscription) use ($transport, $body, $ttl) {
            $result = $transport->send($subscription, $body, $ttl);
            if ($result === PushResult::Expired) {
                $subscription->delete();
            } elseif ($result === PushResult::Sent) {
                PushSubscription::query()->whereKey($subscription->id)->update(['last_success_at' => Carbon::now()]);
            }
        });
    }
}
