<?php

namespace App\Services\Import;

use App\Filament\Resources\ImportResource\Pages\ReviewMapping;
use App\Models\Import;
use App\Models\User;
use Filament\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * WP3.3 (audit H24) — an unattended import (SFTP / API) only runs when its
 * column mapping is certain. Otherwise it waits in "mapping review" and the
 * tenant's admins are told, instead of silently loading columns into the
 * wrong fields.
 */
class MappingReviewGate
{
    public function __construct(private ColumnMappingService $mapper)
    {
    }

    /** True when the import was held for review (the caller must not process it). */
    public function holdIfUncertain(Import $import): bool
    {
        $mappings = $import->columnMaps()->orderBy('sort_order')->get()
            ->map(fn ($m) => [
                'source_header' => $m->source_header,
                'target_field'  => $m->is_skipped ? null : $m->target_field,
                'confidence'    => (float) $m->confidence,
                'reasoning'     => (string) $m->reasoning,
            ])->all();

        $reason = $this->mapper->reviewReason($mappings, (string) $import->data_type);
        if ($reason === null) {
            return false;
        }

        $import->update([
            'status'        => Import::STATUS_MAPPING_REVIEW,
            'error_message' => 'Waiting for a person to confirm the column mapping: ' . $reason,
        ]);

        $this->notifyAdmins($import, $reason);

        return true;
    }

    private function notifyAdmins(Import $import, string $reason): void
    {
        $admins = User::active()->where('tenant_id', $import->tenant_id)->where('is_tenant_admin', true)->get();
        foreach ($admins as $admin) {
            try {
                $note = Notification::make()
                    ->title('An automatic import needs your review')
                    ->body("'{$import->original_filename}' was not loaded: {$reason}")
                    ->warning();
                try {
                    $note->actions([
                        NotificationAction::make('review')->label('Review mapping')
                            ->url(ReviewMapping::getUrl(['record' => $import, 'tenant' => $import->tenant], panel: 'admin')),
                    ]);
                } catch (\Throwable) {
                    // URL generation needs a tenant slug; the bell still goes out.
                }
                $note->sendToDatabase($admin);
            } catch (\Throwable $e) {
                Log::warning('Mapping review notice failed', ['import_id' => $import->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
