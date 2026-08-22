<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SftpConnection — M14. A tenant's SFTP endpoint for automated flat-file pulls.
 * Credentials are encrypted at rest.
 */
class SftpConnection extends Model
{
    const AUTH_PASSWORD = 'password';
    const AUTH_KEY      = 'key';

    const STATUS_NEVER = 'never';
    const STATUS_OK    = 'ok';
    const STATUS_ERROR = 'error';

    protected $fillable = [
        'tenant_id',
        'name',
        'host',
        'port',
        'username',
        'auth_type',
        'password',
        'private_key',
        'private_key_passphrase',
        'base_path',
        'is_active',
        'status',
        'last_polled_at',
        'last_error',
    ];

    protected $casts = [
        'port'                   => 'integer',
        'is_active'              => 'boolean',
        'last_polled_at'         => 'datetime',
        // Credentials encrypted at rest
        'password'               => 'encrypted',
        'private_key'            => 'encrypted',
        'private_key_passphrase' => 'encrypted',
    ];

    protected $hidden = ['password', 'private_key', 'private_key_passphrase'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function feeds(): HasMany
    {
        return $this->hasMany(SftpFeed::class);
    }

    public function ingestedFiles(): HasMany
    {
        return $this->hasMany(SftpIngestedFile::class);
    }

    public function getStatusColor(): string
    {
        return match ($this->status) {
            self::STATUS_OK    => 'success',
            self::STATUS_ERROR => 'danger',
            default            => 'gray',
        };
    }

    /**
     * Build the Laravel/Flysystem SFTP disk configuration for this connection.
     *
     * @return array<string,mixed>
     */
    public function diskConfig(): array
    {
        $config = [
            'driver'   => 'sftp',
            'host'     => $this->host,
            'port'     => $this->port ?: 22,
            'username' => $this->username,
            'root'     => $this->base_path ?: '',
            'timeout'  => 30,
        ];

        if ($this->auth_type === self::AUTH_KEY && $this->private_key) {
            $config['privateKey'] = $this->private_key;
            if ($this->private_key_passphrase) {
                $config['passphrase'] = $this->private_key_passphrase;
            }
        } else {
            $config['password'] = $this->password;
        }

        return $config;
    }
}
