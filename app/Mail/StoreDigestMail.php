<?php

namespace App\Mail;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Stores\StoreDigestService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * W12 — a store manager's daily digest: their store(s) only. Every link in it
 * is signed for this person, so they can confirm findings and enter counts
 * without an account password.
 */
class StoreDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $user,
        public readonly ?string $since = null,   // the previous digest (ISO), for "new" markers
    ) {
    }

    private function data(): ?array
    {
        return app(StoreDigestService::class)->forUser($this->user, $this->since ? \Illuminate\Support\Carbon::parse($this->since) : null);
    }

    public function envelope(): Envelope
    {
        $d = $this->data();
        $where = $d ? implode(', ', array_slice($d['store_names'], 0, 2)) . (count($d['store_names']) > 2 ? ' +' . (count($d['store_names']) - 2) : '') : 'your store';
        $parts = array_filter([
            $d && $d['finding_count'] ? $d['finding_count'] . ' to check' : null,
            $d && $d['count_total'] ? $d['count_total'] . ' to count' : null,
        ]);

        return new Envelope(subject: "[Autnyx] {$where}: " . (implode(', ', $parts) ?: 'today') . ' — ' . now()->format('j M'));
    }

    public function content(): Content
    {
        $svc = app(StoreDigestService::class);

        return new Content(view: 'mail.store-digest', with: [
            'd'           => $this->data() ?? [],
            'sheetUrl'    => $svc->sheetUrl($this->user),
            'unsubscribe' => $svc->unsubscribeUrl($this->user),
            'feedbackUrl' => fn ($anomaly, string $verdict) => $svc->feedbackUrl($this->user, $anomaly, $verdict),
            'money'       => fn (float $v) => \App\Support\Money::compact($v, \App\Support\Money::normalize($this->tenant->currency)),
            'ruleLabel'   => fn ($a) => $a->getRuleLabel(),
        ]);
    }
}
