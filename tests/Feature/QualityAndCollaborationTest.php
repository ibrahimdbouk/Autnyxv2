<?php

namespace Tests\Feature;

use App\Models\Anomaly;
use App\Models\AuditLog;
use App\Models\CommentMention;
use App\Models\Investigation;
use App\Models\InvestigationComment;
use App\Services\Collaboration\CommentService;
use App\Services\Quality\QualityMetricsService;
use Tests\TestCase;

class QualityAndCollaborationTest extends TestCase
{
    public function test_quality_rates_reflect_false_positives(): void
    {
        $tenant = $this->createTenant();

        Anomaly::factory()->create(['tenant_id' => $tenant->id]);
        Anomaly::factory()->create(['tenant_id' => $tenant->id, 'is_false_positive' => true, 'dismissed_at' => now()]);

        $rates = (new QualityMetricsService())->rates($tenant->id);

        $this->assertSame(50.0, $rates['false_positive_rate']);
        $this->assertSame(2, $rates['counts']['anomalies']);
    }

    public function test_overall_score_withheld_until_statistically_meaningful(): void
    {
        $tenant = $this->createTenant();
        Investigation::factory()->create(['tenant_id' => $tenant->id, 'status' => 'resolved']);

        $score = (new QualityMetricsService())->overallScore($tenant->id);
        $this->assertFalse($score['available']);
    }

    public function test_posting_comment_creates_mention_and_timeline_entry(): void
    {
        $tenant   = $this->createTenant();
        $author   = $this->createUser($tenant);
        $mentioned = $this->createUser($tenant);
        $inv      = Investigation::factory()->create(['tenant_id' => $tenant->id]);

        $comment = app(CommentService::class)->post($inv, $author->id, 'Please review this', [$mentioned->id]);

        $this->assertInstanceOf(InvestigationComment::class, $comment);
        $this->assertTrue(
            CommentMention::where('comment_id', $comment->id)->where('mentioned_user_id', $mentioned->id)->exists()
        );
        $this->assertTrue(
            AuditLog::where('investigation_id', $inv->id)->where('event_type', AuditLog::EVENT_COMMENT_ADDED)->exists()
        );
    }

    public function test_comment_soft_delete_preserves_history(): void
    {
        $tenant = $this->createTenant();
        $author = $this->createUser($tenant);
        $inv    = Investigation::factory()->create(['tenant_id' => $tenant->id]);

        $comment = app(CommentService::class)->post($inv, $author->id, 'temp');
        app(CommentService::class)->delete($comment, $author->id);

        $this->assertSoftDeleted('investigation_comments', ['id' => $comment->id]);
        $this->assertSame($author->id, $comment->fresh()->deleted_by);
    }
}
