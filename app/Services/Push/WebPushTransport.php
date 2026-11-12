<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/** Sends encrypted Web Push messages with VAPID (minishlink/web-push). */
class WebPushTransport implements PushTransport
{
    private ?WebPush $client = null;

    public function isConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    public function send(PushSubscription $subscription, string $payload, int $ttlSeconds): PushResult
    {
        try {
            $report = $this->client()->sendOneNotification(
                new Subscription($subscription->endpoint, $subscription->public_key, $subscription->auth_token, $subscription->content_encoding),
                $payload,
                ['TTL' => $ttlSeconds, 'urgency' => 'high'],
            );
        } catch (Throwable $e) {
            Log::warning('Web push failed', ['subscription_id' => $subscription->id, 'error' => $e::class]);

            return PushResult::Failed;
        }
        if ($report->isSuccess()) {
            return PushResult::Sent;
        }
        if ($report->isSubscriptionExpired()) {
            return PushResult::Expired;
        }
        Log::warning('Web push refused', ['subscription_id' => $subscription->id, 'status' => $report->getResponse()?->getStatusCode()]);

        return PushResult::Failed;
    }

    private function client(): WebPush
    {
        return $this->client ??= new WebPush(['VAPID' => [
            'subject' => (string) config('webpush.vapid.subject'),
            'publicKey' => (string) config('webpush.vapid.public_key'),
            'privateKey' => (string) config('webpush.vapid.private_key'),
        ]], [], 10, ['allow_redirects' => false, 'connect_timeout' => 5]);
    }
}
