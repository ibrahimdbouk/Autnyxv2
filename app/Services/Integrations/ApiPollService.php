<?php

namespace App\Services\Integrations;

use App\Models\ApiConnection;
use App\Models\ApiFeed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pulls data from a source system's API and runs it through the STANDARD import
 * pipeline (via PipelineIngestor) — the API sibling of SftpPollService. So API
 * ingest inherits column auto-mapping, validation, dirty-key capture and the
 * async detection trigger with no new downstream code.
 *
 * See claude/api-integration-library.md.
 */
class ApiPollService
{
    public function __construct(
        private ConnectorRegistry $registry,
        private PipelineIngestor $ingestor,
        private ForecastFeedIngestor $forecast,
        private ReplenishmentParamsIngestor $replenParams,
        private PlanningExceptionIngestor $planningExceptions,
    ) {
    }

    /** Poll every active connection for a tenant. Returns feeds ingested. */
    public function pollTenant(int $tenantId): int
    {
        $count = 0;
        foreach (ApiConnection::where('tenant_id', $tenantId)->where('is_active', true)->get() as $connection) {
            $count += $this->pollConnection($connection);
        }

        return $count;
    }

    /** Poll one connection across all its enabled feeds. */
    public function pollConnection(ApiConnection $connection): int
    {
        $ingested  = 0;
        $hadError  = false;
        $connector = $this->registry->for($connection);

        foreach ($connection->feeds()->where('enabled', true)->get() as $feed) {
            try {
                if ($this->ingestFeed($connection, $feed, $connector)) {
                    $ingested++;
                }
            } catch (\Throwable $e) {
                $hadError = true;
                Log::error('[api] feed poll failed', ['feed' => $feed->id, 'error' => $e->getMessage()]);
                $connection->last_error = Str::limit($e->getMessage(), 500);
            }
        }

        $connection->forceFill([
            'status'         => $hadError ? ApiConnection::STATUS_ERROR : ApiConnection::STATUS_OK,
            'last_polled_at' => now(),
            'last_error'     => $hadError ? ($connection->last_error ?: 'One or more feeds failed — see logs.') : null,
        ])->save();

        return $ingested;
    }

    /** Fetch a feed's records and route it: planning baseline, or the import pipeline. */
    private function ingestFeed(ApiConnection $connection, ApiFeed $feed, $connector): bool
    {
        // Planning-layer feeds → the baseline / params, not the import pipeline.
        if ($feed->data_type === ApiFeed::DATA_TYPE_DEMAND_FORECAST) {
            return $this->forecast->ingest($connection, $feed, $connector->fetch($connection, $feed)) > 0;
        }
        if ($feed->data_type === ApiFeed::DATA_TYPE_REPLENISHMENT_PARAMS) {
            return $this->replenParams->ingest($connection, $feed, $connector->fetch($connection, $feed)) > 0;
        }
        if ($feed->data_type === ApiFeed::DATA_TYPE_PLANNING_EXCEPTION) {
            return $this->planningExceptions->ingest($connection, $feed, $connector->fetch($connection, $feed)) > 0;
        }

        $import = $this->ingestor->ingestRows(
            $connection->tenant_id,
            $feed->data_type,
            $connector->fetch($connection, $feed),
            'api',
        );

        return $import !== null;
    }
}
