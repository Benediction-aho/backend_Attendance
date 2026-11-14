<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** In-app report notification (stored synchronously; the push copy is queued by PushInAppNotification). */
class ReportNotice extends Notification
{
    /** @param array<string, mixed> $data */
    public function __construct(public string $kind, public string $title, public string $body, public ?string $path = null, public array $data = []) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'path' => $this->path] + $this->data;
    }
}
