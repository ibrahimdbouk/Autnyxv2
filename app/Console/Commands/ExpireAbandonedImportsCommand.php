<?php

namespace App\Console\Commands;

use App\Models\Import;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * imports:expire-abandoned — WP1.2 (audit C3).
 *
 * An upload left in "uploaded" or "awaiting review" used to block detection for
 * its tenant forever. Detection now only waits for such uploads for
 * detection.pending_import_block_hours; this command retires the stale ones as
 * "abandoned" (resumable from the Imports list) and tells the uploader.
 */
class ExpireAbandonedImportsCommand extends Command
{
    protected $signature = 'imports:expire-abandoned
        {--hours= : Age threshold (default: config detection.pending_import_block_hours)}
        {--dry : Report without changing anything}';

    protected $description = 'Mark stale uploaded / awaiting-review imports as abandoned so they cannot block detection';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? config('detection.pending_import_block_hours', 24));
        $cutoff = now()->subHours(max(1, $hours));
        $dry = (bool) $this->option('dry');

        $stale = Import::whereIn('status', [Import::STATUS_UPLOADED, Import::STATUS_MAPPING_REVIEW])
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $import) {
            $this->line("Import #{$import->id} (tenant {$import->tenant_id}, {$import->status}) → abandoned");
            if ($dry) {
                continue;
            }

            $import->update([
                'status'        => Import::STATUS_ABANDONED,
                'error_message' => "Not confirmed within {$hours}h — marked abandoned so it no longer holds up detection. Resume it from the Imports list if it is still needed.",
            ]);

            if ($import->user) {
                try {
                    Notification::make()
                        ->title('Import set aside')
                        ->body("“{$this->label($import)}” was left awaiting review for more than {$hours}h, so it has been marked abandoned. You can resume it from Imports.")
                        ->warning()
                        ->sendToDatabase($import->user);
                } catch (\Throwable) {
                    // best-effort
                }
            }
        }

        $this->info(($dry ? '[dry] ' : '') . "{$stale->count()} stale import(s) " . ($dry ? 'would be' : '') . ' marked abandoned.');
        Log::info("[imports:expire-abandoned] {$stale->count()} import(s) marked abandoned" . ($dry ? ' (dry run)' : ''));

        return self::SUCCESS;
    }

    private function label(Import $import): string
    {
        return (string) ($import->original_filename ?: ('import #' . $import->id));
    }
}
