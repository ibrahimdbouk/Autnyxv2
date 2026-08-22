<?php

namespace App\Models;

use App\Models\Import;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SftpFeed — M14. Maps a remote directory + filename pattern to an import type.
 */
class SftpFeed extends Model
{
    protected $fillable = [
        'sftp_connection_id',
        'tenant_id',
        'data_type',
        'remote_path',
        'filename_pattern',
        'archive_path',
        'delete_after',
        'enabled',
    ];

    protected $casts = [
        'delete_after' => 'boolean',
        'enabled'      => 'boolean',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SftpConnection::class, 'sftp_connection_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function getDataTypeLabel(): string
    {
        return Import::dataTypeLabels()[$this->data_type] ?? $this->data_type;
    }

    /**
     * Does a filename match this feed's glob pattern (case-insensitive)?
     */
    public function matches(string $filename): bool
    {
        $pattern = $this->filename_pattern ?: '*';
        return fnmatch($pattern, $filename, FNM_CASEFOLD);
    }
}
