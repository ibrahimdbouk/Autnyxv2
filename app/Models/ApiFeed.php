<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ApiFeed — maps one source-system endpoint to an Autnyx import type, plus how to
 * read it (records_path, field_map, pagination). The API sibling of SftpFeed.
 */
class ApiFeed extends Model
{
    /**
     * A planning-layer data type that does NOT go through the transactional import
     * pipeline — it lands in the planning baseline (plan_forecasts) via
     * ForecastFeedIngestor. Distinct from Import::TYPE_* (sales/inventory/…).
     */
    public const DATA_TYPE_DEMAND_FORECAST = 'demand_forecast';

    protected $fillable = [
        'api_connection_id',
        'tenant_id',
        'data_type',
        'endpoint',
        'records_path',
        'field_map',
        'params',
        'page_strategy',
        'page_size',
        'enabled',
    ];

    protected $casts = [
        'field_map' => 'array',
        'params'    => 'array',
        'page_size' => 'integer',
        'enabled'   => 'boolean',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(ApiConnection::class, 'api_connection_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function getDataTypeLabel(): string
    {
        return Import::dataTypeLabels()[$this->data_type] ?? $this->data_type;
    }
}
