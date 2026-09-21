<?php

namespace App\Services\Teams;

use App\Models\TeamsConnection;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fills users' teams_aad_user_id from their email via Graph, so admins don't
 * map every user to Teams by hand. Best-effort and dormant until the tenant has
 * an active Teams connection and TEAMS_ENABLED is on.
 *
 * See claude/teams-notifications.md.
 */
class TeamsUserResolver
{
    public function __construct(private GraphClient $graph)
    {
    }

    /**
     * Resolve and store teams_aad_user_id for a tenant's users.
     *
     * @param  bool  $force  also re-resolve users that already have a mapping
     * @return array{updated:int,skipped:int,failed:int}
     */
    public function resyncTenant(int $tenantId, bool $force = false): array
    {
        $out = ['updated' => 0, 'skipped' => 0, 'failed' => 0];

        if (! config('services.teams.enabled')) {
            return $out;
        }

        $conn = TeamsConnection::where('tenant_id', $tenantId)->where('is_active', true)->first();
        if (! $conn) {
            return $out;
        }

        $query = User::where('tenant_id', $tenantId)->whereNotNull('email');
        if (! $force) {
            $query->whereNull('teams_aad_user_id');
        }

        foreach ($query->cursor() as $user) {
            if (empty($user->email)) {
                $out['skipped']++;
                continue;
            }

            try {
                $aadId = $this->graph->resolveUserIdByEmail($conn, $user->email);
                if ($aadId === null) {
                    $out['skipped']++;
                    continue;
                }
                if ($user->teams_aad_user_id === $aadId) {
                    $out['skipped']++;
                    continue;
                }
                $user->forceFill(['teams_aad_user_id' => $aadId])->saveQuietly();
                $out['updated']++;
            } catch (Throwable $e) {
                $out['failed']++;
                Log::error('[TeamsUserResolver] ' . $e->getMessage(), ['tenant_id' => $tenantId, 'user_id' => $user->id]);
            }
        }

        return $out;
    }
}
