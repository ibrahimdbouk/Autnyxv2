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
    /** WP3.7 — first sighting; taken on a later poll once size + mtime are unchanged. */
    const STATUS_PENDING  = 'pending';

    /** WP3.7 — a failed file is retried with backoff up to this many attempts. */
    const MAX_ATTEMPTS = 5;

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
        'remote_mtime',
        'attempts',
        'next_attempt_at',
    ];

    protected $casts = [
        'size_bytes'   => 'integer',
        'processed_at' => 'datetime',
        'remote_mtime' => 'integer',
        'attempts'     => 'integer',
        'next_attempt_at' => 'datetime',
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
