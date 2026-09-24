<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\Import\ImportProcessorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * WP2.3 (audit H13) — process a public-API ingest OFF the HTTP request. The
 * endpoint used to insert up to 200k rows and run detection inside the request.
 */
class ProcessIngestedImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(public int $importId)
    {
    }

    public function handle(ImportProcessorService $processor): void
    {
        $import = Import::find($this->importId);
        if ($import && $import->status === Import::STATUS_UPLOADED) {
            $processor->process($import);
        }
    }
}
