<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An API key for the outbound public API. Only the SHA-256 hash of the token is
 * stored; the plaintext is shown once at creation. Scoped, revocable, optional
 * expiry. See claude/public-api.md.
 */
class ApiKey extends Model
{
    public const SCOPE_READ_INVESTIGATIONS = 'read:investigations';
    public const SCOPE_READ_ANOMALIES      = 'read:anomalies';
    public const SCOPE_READ_RECOVERIES     = 'read:recoveries';
    public const SCOPE_READ_DATA_HEALTH    = 'read:data_health';
    public const SCOPE_WRITE_INGEST        = 'write:ingest';

    /** @return array<string,string> scope => label */
    public static function scopeOptions(): array
    {
        return [
            self::SCOPE_READ_INVESTIGATIONS => 'Read investigations',
            self::SCOPE_READ_ANOMALIES      => 'Read anomalies',
            self::SCOPE_READ_RECOVERIES     => 'Read recoveries',
            self::SCOPE_READ_DATA_HEALTH    => 'Read data health',
            self::SCOPE_WRITE_INGEST        => 'Ingest data (write)',
        ];
    }

    protected $fillable = [
        'tenant_id',
        'name',
        'prefix',
        'key_hash',
        'scopes',
        'last_used_at',
        'expires_at',
        'revoked_at',
        'created_by',
    ];

    protected $casts = [
        'scopes'       => 'array',
        'last_used_at' => 'datetime',
        'expires_at'   => 'datetime',
        'revoked_at'   => 'datetime',
    ];

    protected $hidden = ['key_hash'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** SHA-256 of a presented token — the value stored in key_hash. */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Mint a new key. Returns the saved model and the ONE-TIME plaintext token
     * (never retrievable again).
     *
     * @param  array<int,string>  $scopes
     * @return array{0:self,1:string}
     */
    public static function generate(int $tenantId, string $name, array $scopes, ?\DateTimeInterface $expiresAt = null, ?int $createdBy = null): array
    {
        $token  = 'atx_' . Str::random(40);
        $model = self::create([
            'tenant_id'  => $tenantId,
            'name'       => $name,
            'prefix'     => substr($token, 0, 12),
            'key_hash'   => self::hashToken($token),
            'scopes'     => array_values($scopes),
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ]);

        return [$model, $token];
    }

    /** Look up an active (not revoked, not expired) key for a presented token. */
    public static function findActiveByToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        $key = self::where('key_hash', self::hashToken($token))->first();

        return $key && $key->isActive() ? $key : null;
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, is_array($this->scopes) ? $this->scopes : [], true);
    }

    /** Stamp last_used_at at most once a minute (avoids a write per request). */
    public function touchUsed(): void
    {
        if ($this->last_used_at && $this->last_used_at->gt(now()->subMinute())) {
            return;
        }

        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
