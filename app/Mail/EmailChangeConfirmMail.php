<?php

namespace App\Mail;

class EmailChangeConfirmMail extends SimpleMail
{
    public function __construct(public string $firstName, public string $token, public string $newEmail, public int $minutes, public bool $toOldAddress) {}

    protected function subjectLine(): string
    {
        return 'Confirm your new email address';
    }

    protected function body(): array
    {
        return [
            'greeting' => "Hello {$this->firstName},",
            'lines' => [
                $this->toOldAddress
                    ? "A change of your sign-in email to {$this->newEmail} was requested. As a super admin, you must also confirm it from this address."
                    : "Please confirm that {$this->newEmail} is your new sign-in email address.",
            ],
            'actionUrl' => self::frontendUrl('/confirm-email?token='.urlencode($this->token)),
            'actionText' => 'Confirm the change',
            'outro' => ["This link expires in {$this->minutes} minutes.", 'If you did not ask for this change, ignore this email and tell an administrator.'],
        ];
    }
}
