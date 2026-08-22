<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SftpIngestedFile — M14 idempotency ledger for pulled files.
 */
class SftpIngestedFile extends Model
{
    const STATUS_IMPORTED = 'imported';
    const STATUS_FAILED   = 'failed';

    protected $fillable = [
        'tenant_id',
        'sftp_connection_id',
        'sftp_feed_id',
        'remote_path',
        'filename',
        'size_bytes',
        'checksum',
        'import_id',
        'status',
        'error',
        'processed_at',
    ];

    protected $casts = [
        'size_bytes'   => 'integer',
        'processed_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SftpConnection::class, 'sftp_connection_id');
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
