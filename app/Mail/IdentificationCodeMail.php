<?php

namespace App\Mail;

use App\Support\TimeFormat;
use Illuminate\Support\Carbon;

class IdentificationCodeMail extends SimpleMail
{
    public function __construct(public string $firstName, public string $code, public Carbon $expiresAt, public bool $isNewAccount) {}

    protected function subjectLine(): string
    {
        return $this->isNewAccount ? 'Your account is ready' : 'Your new identification code';
    }

    protected function body(): array
    {
        return [
            'greeting' => "Hello {$this->firstName},",
            'lines' => [
                $this->isNewAccount
                    ? 'Your Assistance Ghana Attendance account has been approved.'
                    : 'A new identification code has been generated for your account. Your previous code no longer works.',
                'Sign in with your email address and this identification code:',
            ],
            'highlight' => $this->code,
            'actionUrl' => self::frontendUrl('/sign-in'),
            'actionText' => 'Sign in',
            'outro' => [
                'This code is valid until '.TimeFormat::dateTime($this->expiresAt).' (Accra time). You will be asked to replace it with your own password after signing in.',
                'Never share this code. Our team will never ask you for it.',
            ],
        ];
    }
}
