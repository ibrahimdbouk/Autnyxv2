<?php

namespace App\Services\AI;

use App\Models\Tenant;
use App\Support\Tenancy\TenantClock;
use Illuminate\Support\Facades\DB;

/**
 * WP5.4 (D7) — each tenant's daily AI allowance (default 300 calls and 2M
 * tokens a day, overridable per tenant in settings.ai_daily_calls /
 * settings.ai_daily_tokens from Ops). A suspended tenant, or one without the
 * Root-Cause app, gets no AI at all.
 */
class AiBudget
{
    public function allows(int $tenantId): ?string
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            return 'unknown_tenant';
        }
        if ($tenant->status === Tenant::STATUS_SUSPENDED) {
            return 'tenant_suspended';
        }
        if (! $tenant->hasApp(Tenant::APP_ROOT_CAUSE)) {
            return 'no_app_entitlement';
        }

        [$calls, $tokens] = $this->limits($tenant);
        $used = $this->today($tenantId);
        if ($used && ((int) $used->calls >= $calls || ((int) $used->input_tokens + (int) $used->output_tokens) >= $tokens)) {
            $this->bump($tenantId, ['refused' => 1]);

            return 'daily_budget_exhausted';
        }

        return null;
    }

    public function record(int $tenantId, int $inputTokens, int $outputTokens): void
    {
        $this->bump($tenantId, ['calls' => 1, 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens]);
    }

    /** @return array{0:int,1:int} [calls, tokens] a day */
    public function limits(Tenant $tenant): array
    {
        $s = (array) ($tenant->settings ?? []);

        return [
            (int) ($s['ai_daily_calls'] ?? config('ai.daily_calls', 300)),
            (int) ($s['ai_daily_tokens'] ?? config('ai.daily_tokens', 2_000_000)),
        ];
    }

    public function today(int $tenantId): ?object
    {
        return DB::table('ai_usage_daily')->where('tenant_id', $tenantId)
            ->where('day', TenantClock::localDate($tenantId))->first();
    }

    /** @param array<string,int> $inc */
    private function bump(int $tenantId, array $inc): void
    {
        $day  = TenantClock::localDate($tenantId);
        $cols = ['calls', 'input_tokens', 'output_tokens', 'refused'];
        $vals = array_map(fn ($c) => (int) ($inc[$c] ?? 0), $cols);
        DB::statement(
            'INSERT INTO ai_usage_daily (tenant_id, day, calls, input_tokens, output_tokens, refused, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON CONFLICT (tenant_id, day) DO UPDATE SET
                calls = ai_usage_daily.calls + EXCLUDED.calls,
                input_tokens = ai_usage_daily.input_tokens + EXCLUDED.input_tokens,
                output_tokens = ai_usage_daily.output_tokens + EXCLUDED.output_tokens,
                refused = ai_usage_daily.refused + EXCLUDED.refused,
                updated_at = NOW()',
            array_merge([$tenantId, $day], $vals)
        );
    }
}
