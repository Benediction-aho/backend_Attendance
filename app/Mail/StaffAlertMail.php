<?php

namespace App\Mail;

class StaffAlertMail extends SimpleMail
{
    public function __construct(public string $title, public string $text, public ?string $path) {}

    protected function subjectLine(): string
    {
        return $this->title;
    }

    protected function body(): array
    {
        return [
            'greeting' => 'Hello,',
            'lines' => [$this->text],
            'actionUrl' => $this->path !== null ? self::appUrl($this->path) : null,
            'actionText' => 'Open',
        ];
    }
}
