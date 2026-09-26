<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Store extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'address',
        'city',
        'region',
        'country',
        'format',
        // Hardening (2026-09-23) — geography + operating attributes.
        'postal_code',
        'latitude',
        'longitude',
        'phone',
        'email',
        'timezone',
        'currency',
        'banner',
        'status',
        'opened_on',
        'sales_area_sqm',
        // Platform core (org structure).
        'area',
        'geofence_radius_m',
    ];

    /** Radius used when neither the store nor the tenant sets one (metres). */
    public const DEFAULT_GEOFENCE_M = 150;

    protected $casts = [
        'latitude'       => 'decimal:7',
        'longitude'      => 'decimal:7',
        'sales_area_sqm' => 'decimal:2',
        'opened_on'      => 'date',
        'geofence_radius_m' => 'integer',
    ];

    /** WP6.5: the canonical hierarchy follows (once per tenant, at the end of the request). */
    protected static function booted(): void
    {
        static::saved(fn (self $m) => \App\Services\Platform\HierarchySync::later((int) $m->tenant_id));
        static::deleted(fn (self $m) => \App\Services\Platform\HierarchySync::later((int) $m->tenant_id));
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function salesTransactions(): HasMany
    {
        return $this->hasMany(SalesTransaction::class);
    }

    public function inventoryLevels(): HasMany
    {
        return $this->hasMany(InventoryLevel::class);
    }

    /** WP6.2: the store's current stock positions (one per SKU). */
    public function currentPositions(): HasMany
    {
        return $this->hasMany(InventoryCurrent::class);
    }

    /** This store's behavioural feature vector (Platform\Intelligence), if computed. */
    public function feature(): HasOne
    {
        return $this->hasOne(StoreFeature::class);
    }

    /** Platform core: places inside the store (aisles, endcaps, chillers…). */
    public function zones(): HasMany
    {
        return $this->hasMany(StoreZone::class);
    }

    /** Platform core: the people linked to this store (store managers, associates). */
    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_user')->withPivot('tenant_id')->withTimestamps();
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** The on-site radius: the store's own, else the tenant default, else 150 m. */
    public function geofenceRadius(): int
    {
        if ($this->geofence_radius_m) {
            return (int) $this->geofence_radius_m;
        }
        $tenantDefault = (int) (($this->tenant?->settings ?? [])['geofence_radius_m'] ?? 0);

        return $tenantDefault > 0 ? $tenantDefault : self::DEFAULT_GEOFENCE_M;
    }

    /** Great-circle distance in metres from the store to a point; null when the store has no coordinates. */
    public function distanceTo(float $lat, float $lng): ?float
    {
        if (! $this->hasCoordinates()) {
            return null;
        }
        $r = 6371000.0;
        $p1 = deg2rad((float) $this->latitude);
        $p2 = deg2rad($lat);
        $dp = $p2 - $p1;
        $dl = deg2rad($lng - (float) $this->longitude);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * Is a reported position on site? The phone's accuracy (metres) is
     * allowed for, capped so a vague fix cannot pass from far away. Null when
     * the store has no coordinates (the check cannot be made).
     */
    public function isOnSite(float $lat, float $lng, ?float $accuracyM = null): ?bool
    {
        $d = $this->distanceTo($lat, $lng);
        if ($d === null) {
            return null;
        }
        $slack = min(max(0.0, (float) $accuracyM), 100.0);

        return $d <= $this->geofenceRadius() + $slack;
    }

    /** This store's leaf node in the canonical Location hierarchy (P1.1). */
    public function locationNode(): HasOne
    {
        return $this->hasOne(LocationNode::class);
    }
}
