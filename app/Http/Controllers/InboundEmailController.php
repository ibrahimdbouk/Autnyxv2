<?php

namespace App\Http\Controllers;

use App\Models\Investigation;
use App\Models\InvestigationComment;
use App\Models\User;
use App\Services\Collaboration\CommentService;
use App\Services\Collaboration\EmailReplyToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * InboundEmailController — turns a user's email reply into an investigation
 * comment (source = email), captured in the audit log.
 *
 * Provider-agnostic: accepts the common field names used by Mailgun, Postmark
 * and SendGrid inbound webhooks. Protected by a shared secret (config inbound.secret);
 * the endpoint is disabled until that secret is set. The sender must be a known
 * user of the investigation's tenant, and the reply must carry a valid signed
 * token (in the recipient plus-address or the subject).
 */
class InboundEmailController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $secret = config('inbound.secret');
        $provided = $request->header('X-Webhook-Secret') ?? $request->query('secret') ?? $request->input('secret');
        if (empty($secret) || ! is_string($provided) || ! hash_equals((string) $secret, $provided)) {
            abort(403, 'Inbound email is not enabled or the secret is invalid.');
        }

        $from      = $this->firstNonEmpty($request, ['from', 'From', 'sender', 'envelope_from']);
        $to        = $this->firstNonEmpty($request, ['to', 'To', 'recipient', 'envelope_to', 'OriginalRecipient']);
        $subject   = $this->firstNonEmpty($request, ['subject', 'Subject']) ?? '';
        $body      = $this->firstNonEmpty($request, ['stripped-text', 'StrippedTextReply', 'text', 'TextBody', 'body-plain', 'plain']) ?? '';
        $messageId = $this->firstNonEmpty($request, ['message-id', 'MessageID', 'Message-Id', 'message_id', 'MessageId']);

        $fromEmail = $this->extractEmail((string) $from);
        $token     = $this->tokenFromRecipient((string) $to) ?? $this->tokenFromSubject((string) $subject);

        // Every non-fatal condition returns 200 so the provider doesn't retry/bounce.
        if (! $token) {
            return $this->ignored('no reply token found');
        }

        $investigationId = EmailReplyToken::resolve($token);
        if (! $investigationId) {
            return $this->ignored('invalid token');
        }

        $investigation = Investigation::find($investigationId);
        if (! $investigation) {
            return $this->ignored('investigation not found');
        }

        if (! $fromEmail) {
            return $this->ignored('no sender address');
        }

        $user = User::where('tenant_id', $investigation->tenant_id)
            ->whereRaw('LOWER(email) = ?', [strtolower($fromEmail)])
            ->first();
        if (! $user) {
            // Security: only known tenant users may post via email.
            return $this->ignored('sender is not a user of this tenant');
        }

        $clean = $this->stripQuoted((string) $body);
        if ($clean === '') {
            return $this->ignored('empty message body');
        }

        app(CommentService::class)->post(
            $investigation,
            $user->id,
            $clean,
            [],
            [],
            null,
            InvestigationComment::SOURCE_EMAIL,
            $messageId ? (string) $messageId : null,
        );

        return response()->json(['status' => 'ok']);
    }

    private function ignored(string $reason): JsonResponse
    {
        Log::info('[inbound-email] ignored: ' . $reason);
        return response()->json(['status' => 'ignored', 'reason' => $reason]);
    }

    private function firstNonEmpty(Request $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            $val = $request->input($key);
            if (is_string($val) && trim($val) !== '') {
                return $val;
            }
        }
        return null;
    }

    private function extractEmail(string $value): ?string
    {
        if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $value, $m)) {
            return $m[0];
        }
        return null;
    }

    private function tokenFromRecipient(string $recipients): ?string
    {
        // reply+<token>@domain  — scan all comma-separated recipients
        foreach (preg_split('/[,;]/', $recipients) as $addr) {
            if (preg_match('/\+([A-Za-z0-9\-]+)@/', $addr, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private function tokenFromSubject(string $subject): ?string
    {
        if (preg_match('/\[INV-([A-Za-z0-9\-]+)\]/', $subject, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Trim the quoted reply chain so only the user's new text is kept.
     */
    private function stripQuoted(string $body): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $body);
        $kept  = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Common quote markers
            if (str_starts_with($trimmed, '>')) {
                break;
            }
            if (preg_match('/^On .+ wrote:$/', $trimmed)) {
                break;
            }
            if (preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', $trimmed)) {
                break;
            }
            if (preg_match('/^From:\s.+@/', $trimmed)) {
                break;
            }
            $kept[] = $line;
        }
        return trim(implode("\n", $kept));
    }
}
