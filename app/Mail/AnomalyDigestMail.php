<?php

namespace App\Mail;

use App\Models\AnomalySetting;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AnomalyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public int $highCount;
    public int $mediumCount;
    public string $panelUrl;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly Collection $anomalies,
    ) {
        $this->highCount   = $anomalies->where('severity', 'high')->count();
        $this->mediumCount = $anomalies->where('severity', 'medium')->count();
        $this->panelUrl    = rtrim(config('app.url'), '/') . '/admin/' . $tenant->slug . '/anomalies';
    }

    public function envelope(): Envelope
    {
        $parts = [];
        if ($this->highCount > 0)   $parts[] = "{$this->highCount} high";
        if ($this->mediumCount > 0) $parts[] = "{$this->mediumCount} medium";
        $summary = implode(', ', $parts);

        return new Envelope(
            subject: "[Autnyx] {$summary} anomaly alert — {$this->tenant->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.anomaly-digest',
        );
    }

    /**
     * Return a human-readable label for a rule_type key.
     */
    public function ruleLabel(string $ruleType): string
    {
        return AnomalySetting::RULES[$ruleType]['label'] ?? ucwords(str_replace('_', ' ', $ruleType));
    }
}
