<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'settings',
        'notification_email',
        'notify_on_high',
        'notify_on_medium',
    ];

    protected $casts = [
        'settings'         => 'array',
        'notify_on_high'   => 'boolean',
        'notify_on_medium' => 'boolean',
    ];

    // ---------- Relationships ----------

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
