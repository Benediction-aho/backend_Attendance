<?php

namespace App\Listeners;

use App\Jobs\SendWebPush;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Every in-app notification (database channel) also goes to the user's phones by Web Push (SPECS 8),
 * from a queued job dispatched after the transaction commits: the request never waits for the push.
 */
class PushInAppNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User || ! method_exists($event->notification, 'toArray')) {
            return;
        }
        /** @var array<string, mixed> $data */
        $data = $event->notification->toArray($event->notifiable);
        $kind = is_string($data['kind'] ?? null) ? $data['kind'] : 'notification';

        SendWebPush::dispatch($event->notifiable->id, $kind, [
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'path' => $data['path'] ?? null,
        ])->afterCommit();
    }
}
