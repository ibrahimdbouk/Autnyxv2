<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * NotificationMail — the email counterpart of an in-app notification.
 *
 * Generic, event-agnostic: watches, @mentions, escalations, data-health alerts
 * and bulk-action completions all render through this single template. Sent
 * best-effort by NotificationDispatcher; a failure never breaks the originating
 * flow.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly ?string $bodyText = null,
        public readonly ?string $actionUrl = null,
        public readonly ?string $greetingName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '[Autnyx] ' . $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.notification',
        );
    }
}
