<?php

namespace App\Mail;

/**
 * Short security notice to the account owner (email change requested/done, password changed, account locked…).
 */
class SecurityAlertMail extends SimpleMail
{
    /** @param list<string> $lines */
    public function __construct(public string $firstName, public string $subjectText, public array $lines) {}

    protected function subjectLine(): string
    {
        return $this->subjectText;
    }

    protected function body(): array
    {
        return [
            'greeting' => "Hello {$this->firstName},",
            'lines' => $this->lines,
            'outro' => ['If this was not you, contact an administrator immediately.'],
        ];
    }
}
