<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CampaignReview — when a tenant last reviewed a given Action Queue campaign.
 * Drives the campaign SLA / cadence ("due" vs "reviewed Nd ago").
 */
class CampaignReview extends Model
{
    protected $fillable = [
        'tenant_id',
        'campaign',
        'last_reviewed_at',
    ];

    protected $casts = [
        'last_reviewed_at' => 'datetime',
    ];
}
