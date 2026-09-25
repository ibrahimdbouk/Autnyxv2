<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * WP6.7 — platform-level audit (not tenant-scoped): tenant export, erasure
 * request and completion. Survives the tenant it describes.
 */
class PlatformAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public static function record(string $event, ?Model $subject, array $details = [], ?User $actor = null, ?string $label = null): self
    {
        $actor ??= auth()->user();

        return self::create([
            'actor_user_id' => $actor?->id,
            'actor_email'   => $actor?->email,
            'event'         => $event,
            'subject_type'  => $subject ? class_basename($subject) : null,
            'subject_id'    => $subject?->getKey(),
            'subject_label' => $label ?? ($subject->name ?? null),
            'details'       => $details,
        ]);
    }
}
