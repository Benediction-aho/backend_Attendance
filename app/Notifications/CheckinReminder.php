<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** In-app check-in reminder (stored synchronously: FIABILITE B5). The push copy is a queued job. */
class CheckinReminder extends Notification
{
    public function __construct(public string $workDate, public string $lateAfter) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'checkin_reminder',
            'title' => 'Time to check in',
            'body' => "You have not checked in yet today. Lateness starts at {$this->lateAfter}.",
            'path' => '/attendance',
            'work_date' => $this->workDate,
        ];
    }
}
