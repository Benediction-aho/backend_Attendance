<?php

namespace App\Mail;

class RecoveryLinkMail extends SimpleMail
{
    public function __construct(public string $firstName, public string $token, public int $minutes) {}

    protected function subjectLine(): string
    {
        return 'Restore access to your account';
    }

    protected function body(): array
    {
        return [
            'greeting' => "Hello {$this->firstName},",
            'lines' => ['Your account was locked after several failed sign-in attempts. Use the button below to restore access.'],
            'actionUrl' => self::frontendUrl('/recover?token='.urlencode($this->token)),
            'actionText' => 'Restore access',
            'outro' => ["This link expires in {$this->minutes} minutes and works only once.", 'If you did not ask for this, please tell another super admin.'],
        ];
    }
}
