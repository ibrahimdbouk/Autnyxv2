<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Investigation;
use App\Models\InvestigationComment;
use App\Services\Collaboration\EmailReplyToken;
use Tests\TestCase;

class InboundEmailCommentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('inbound.secret', 'test-secret');
    }

    public function test_reply_token_round_trips(): void
    {
        $tenant = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);

        $token = EmailReplyToken::for($inv);
        $this->assertSame($inv->id, EmailReplyToken::resolve($token));
        $this->assertNull(EmailReplyToken::resolve($inv->id . '-tampered'));
    }

    public function test_email_reply_creates_comment_and_audit_entry(): void
    {
        $tenant = $this->createTenant();
        $user   = $this->createUser($tenant);
        $user->update(['email' => 'ops@store.test']);
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);

        $token = EmailReplyToken::for($inv);

        $response = $this->postJson('/webhooks/inbound-email', [
            'from'       => 'Ops Team <ops@store.test>',
            'to'         => "reply+{$token}@inbound.autnyx.io",
            'subject'    => 'Re: [Autnyx] Investigation update',
            'text'       => "Looks like a supplier issue, chasing it now.\n\nOn Mon, someone wrote:\n> original message",
            'message-id' => 'msg-123@store.test',
        ], ['X-Webhook-Secret' => 'test-secret']);

        $response->assertOk()->assertJson(['status' => 'ok']);

        $comment = InvestigationComment::where('investigation_id', $inv->id)->first();
        $this->assertNotNull($comment);
        $this->assertSame(InvestigationComment::SOURCE_EMAIL, $comment->source);
        $this->assertSame($user->id, $comment->user_id);
        $this->assertSame('Looks like a supplier issue, chasing it now.', $comment->body);

        $this->assertTrue(
            AuditLog::where('investigation_id', $inv->id)
                ->where('event_type', AuditLog::EVENT_COMMENT_ADDED)
                ->get()
                ->contains(fn ($a) => str_contains((string) $a->description, 'via email'))
        );
    }

    public function test_unknown_sender_is_ignored(): void
    {
        $tenant = $this->createTenant();
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);
        $token = EmailReplyToken::for($inv);

        $response = $this->postJson('/webhooks/inbound-email', [
            'from' => 'stranger@nowhere.test',
            'to'   => "reply+{$token}@inbound.autnyx.io",
            'text' => 'I should not be able to post this.',
        ], ['X-Webhook-Secret' => 'test-secret']);

        $response->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame(0, InvestigationComment::where('investigation_id', $inv->id)->count());
    }

    public function test_duplicate_message_id_is_deduped(): void
    {
        $tenant = $this->createTenant();
        $user   = $this->createUser($tenant);
        $user->update(['email' => 'ops@store.test']);
        $inv = Investigation::factory()->create(['tenant_id' => $tenant->id]);
        $token = EmailReplyToken::for($inv);

        $payload = [
            'from'       => 'ops@store.test',
            'to'         => "reply+{$token}@inbound.autnyx.io",
            'text'       => 'Same message twice',
            'message-id' => 'dup-1@store.test',
        ];

        $this->postJson('/webhooks/inbound-email', $payload, ['X-Webhook-Secret' => 'test-secret'])->assertOk();
        $this->postJson('/webhooks/inbound-email', $payload, ['X-Webhook-Secret' => 'test-secret'])->assertOk();

        $this->assertSame(1, InvestigationComment::where('investigation_id', $inv->id)->count());
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $this->postJson('/webhooks/inbound-email', ['from' => 'x@y.test'], ['X-Webhook-Secret' => 'nope'])
            ->assertStatus(403);
    }
}
