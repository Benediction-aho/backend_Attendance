<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plain transactional email in English. Always queued; the queued payload is encrypted
 * because some of these emails carry one-time codes or links.
 */
abstract class SimpleMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    abstract protected function subjectLine(): string;

    /** @return array{greeting: string, lines: list<string>, highlight?: string|null, actionUrl?: string|null, actionText?: string|null, outro?: list<string>} */
    abstract protected function body(): array;

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        $body = $this->body();

        return new Content(
            view: 'emails.simple',
            text: 'emails.simple_text',
            with: [
                'subjectLine' => $this->subjectLine(),
                'greeting' => $body['greeting'],
                'lines' => $body['lines'],
                'highlight' => $body['highlight'] ?? null,
                'actionUrl' => $body['actionUrl'] ?? null,
                'actionText' => $body['actionText'] ?? null,
                'outro' => $body['outro'] ?? [],
            ],
        );
    }

    /** Public (signed-out) PWA page, e.g. "/sign-in", "/recover?token=…". */
    protected static function frontendUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * Signed-in PWA page. Notification paths are app-relative ("/admin/users/5");
     * the PWA serves them under "/app" (UX34-05).
     */
    public static function appUrl(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        if ($path !== '/app' && ! str_starts_with($path, '/app/') && ! str_starts_with($path, '/app?')) {
            $path = '/app'.($path === '/' ? '' : $path);
        }

        return self::frontendUrl($path);
    }
}
