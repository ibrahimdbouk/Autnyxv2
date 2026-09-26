<?php

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * W11 — the monthly "Value delivered" report: a short summary in the body,
 * the full PDF attached.
 */
class ValueReportMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $panelUrl;

    /** @param array<string,mixed> $report the ReportDataService 'value' payload */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $monthLabel,
        public readonly array $report,
        public readonly string $filename,
    ) {
        $this->panelUrl = rtrim(config('app.url'), '/') . '/admin/' . $tenant->slug . '/reports';
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[Autnyx] Value delivered — {$this->monthLabel} — {$this->tenant->name}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.value-report');
    }

    public function attachments(): array
    {
        // Rendered when the mail is sent (a queued mail must not carry PDF bytes in its payload).
        $report = $this->report;

        return [Attachment::fromData(fn () => \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.pdf.generic', ['r' => $report])
            ->setPaper('a4', 'portrait')->output(), $this->filename)->withMime('application/pdf')];
    }
}
