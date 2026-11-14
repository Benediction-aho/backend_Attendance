<?php

namespace App\Notifications;

use App\Mail\StaffAlertMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * In-app notification (+ optional email) for administrators: new sign-up request, account locked, …
 */
class StaffAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $data */
    public function __construct(
        public string $kind,
        public string $title,
        public string $body,
        public array $data = [],
        public bool $withEmail = true,
        public ?string $path = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->withEmail ? ['database', 'mail'] : ['database'];
    }

    public function toMail(User $notifiable): StaffAlertMail
    {
        return (new StaffAlertMail($this->title, $this->body, $this->path))->to($notifiable->email);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'path' => $this->path] + $this->data;
    }
}
