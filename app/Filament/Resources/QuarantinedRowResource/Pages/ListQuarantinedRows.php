<?php

namespace App\Filament\Resources\QuarantinedRowResource\Pages;

use App\Filament\Resources\QuarantinedRowResource;
use App\Services\DataQuality\AliasSuggester;
use App\Services\DataQuality\QuarantineOps;
use App\Services\DataQuality\Reasons;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListQuarantinedRows extends ListRecords
{
    protected static string $resource = QuarantinedRowResource::class;

    protected function getHeaderActions(): array
    {
        $tenantId = (int) Filament::getTenant()?->id;

        return [
            // W9 (WP9.4): accept SKU aliases the data suggests, then re-screen the rows they unblock.
            Action::make('aliasSuggestions')
                ->label('Alias suggestions')
                ->icon('heroicon-o-light-bulb')
                ->color('gray')
                ->visible(fn () => (bool) auth()->user()?->canManageImports())
                ->modalDescription('Unknown SKUs that match exactly one known SKU once case, spaces, punctuation and leading zeros are ignored. Accepting one maps it for every later batch and re-screens its quarantined rows. Rows already loaded under the unknown SKU keep it.')
                ->form(function () use ($tenantId) {
                    $s = app(AliasSuggester::class)->suggest($tenantId, 100);
                    if ($s === []) {
                        return [Placeholder::make('none')->content('No suggestions — every unknown SKU is either unique or ambiguous.')];
                    }

                    return [CheckboxList::make('accept')->label('Accept')->options(collect($s)->mapWithKeys(fn ($x) => [
                        $x['alias'] . "\x1f" . $x['canonical'] => "{$x['alias']} → {$x['canonical']} ({$x['rows']} rows, {$x['source']})",
                    ])->all())->columns(1)->bulkToggleable()];
                })
                ->action(function (array $data) use ($tenantId) {
                    abort_unless((bool) auth()->user()?->canManageImports(), 403);
                    $pairs = array_map(function ($v) {
                        [$alias, $canonical] = explode("\x1f", $v, 2);

                        return ['alias' => $alias, 'canonical' => $canonical];
                    }, $data['accept'] ?? []);
                    $r = app(QuarantineOps::class)->acceptAliases($tenantId, $pairs, auth()->user());
                    Notification::make()->title("{$r['aliases']} alias(es) saved; {$r['promoted']} quarantined row(s) now loaded")->success()->send();
                }),

            // W9 (WP9.4): act on every open row with one reason, not one page at a time.
            Action::make('byReason')
                ->label('Act on a reason')
                ->icon('heroicon-o-queue-list')
                ->visible(fn () => (bool) auth()->user()?->canManageImports())
                ->form([
                    Select::make('reason')->label('Open rows with reason')->required()
                        ->options(fn () => collect(app(QuarantineOps::class)->openByReason($tenantId))
                            ->mapWithKeys(fn ($n, $code) => [$code => Reasons::label($code) . ' — ' . number_format($n)])->all()),
                    Select::make('data_type')->label('Only this data type')->placeholder('All types')
                        ->options(\App\Models\Import::dataTypeLabels()),
                    Select::make('action')->required()->default('rescreen')->options([
                        'rescreen' => 'Re-screen (load the rows that now pass)',
                        'skip'     => 'Discard (mark skipped)',
                    ]),
                ])
                ->requiresConfirmation()
                ->modalDescription('Up to ' . number_format(QuarantineOps::MAX_PER_RUN) . ' rows per run. Recorded in the audit log.')
                ->action(function (array $data) use ($tenantId) {
                    abort_unless((bool) auth()->user()?->canManageImports(), 403);
                    $r = app(QuarantineOps::class)->applyToReason($tenantId, $data['reason'], $data['action'], $data['data_type'] ?? null, auth()->user());
                    $title = $data['action'] === 'skip'
                        ? "{$r['done']} row(s) discarded"
                        : "{$r['done']} row(s) re-screened: {$r['promoted']} loaded, {$r['still']} still quarantined";
                    Notification::make()->title($title . ($r['remaining'] ? " — {$r['remaining']} left, run again" : ''))->success()->send();
                }),
        ];
    }
}
