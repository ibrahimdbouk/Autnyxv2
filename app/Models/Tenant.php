<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'currency',
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

    // ---------- Currency (display-only) ----------

    /** Normalised ISO currency code for this tenant, always valid. */
    public function currencyCode(): string
    {
        return Money::normalize($this->currency);
    }

    /** Format an amount in this tenant's currency, e.g. "AED 1,234.56". */
    public function money(float|int|null $amount, int $decimals = 2): string
    {
        return Money::format($amount, $this->currencyCode(), $decimals);
    }
}
