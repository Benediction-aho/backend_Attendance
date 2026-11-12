<?php

namespace App\Mail;

class SuperAdminLoginCodeMail extends SimpleMail
{
    public function __construct(public string $firstName, public string $code, public int $minutes) {}

    protected function subjectLine(): string
    {
        return 'Your sign-in code';
    }

    protected function body(): array
    {
        return [
            'greeting' => "Hello {$this->firstName},",
            'lines' => ['Use this one-time code to sign in:'],
            'highlight' => $this->code,
            'outro' => [
                "It expires in {$this->minutes} minutes and works only once.",
                'If you did not try to sign in, you can ignore this email; your account stays safe.',
            ],
        ];
    }
}
