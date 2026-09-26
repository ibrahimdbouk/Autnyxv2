<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuarantinedRowResource\Pages;
use App\Models\EntityAlias;
use App\Models\QuarantinedRow;
use App\Services\DataQuality\Reasons;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Quarantine triage — the rows the firewall rejected, grouped by reason. Review the
 * raw vs cleansed values, then skip (discard) in bulk. Fixing a rule and re-importing
 * is the primary repair loop. See claude/data-quality-firewall.md.
 */
class QuarantinedRowResource extends Resource
{
    protected static ?string $model = QuarantinedRow::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-funnel';

    protected static \UnitEnum|string|null $navigationGroup = 'Data Quality';

    protected static ?string $navigationLabel = 'Quarantine';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ($user->is_super_admin || $user->is_tenant_admin));
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getModel()::query()
            ->where('tenant_id', Filament::getTenant()?->id)
            ->where('status', QuarantinedRow::STATUS_OPEN)
            ->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('import_id')->label('Import')->sortable()->toggleable(),
                TextColumn::make('data_type')->badge()->color('gray'),
                TextColumn::make('row_number')->label('Row')->numeric()->toggleable(),
                TextColumn::make('reason_code')
                    ->label('Reason')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Reasons::label($state))
                    ->color(fn (QuarantinedRow $r) => match ($r->severity) {
                        'high'   => 'danger',
                        'medium' => 'warning',
                        default  => 'info',
                    }),
                TextColumn::make('cleansed_data')
                    ->label('Cleansed')
                    ->formatStateUsing(fn ($state) => is_array($state) ? collect($state)->filter()->map(fn ($v, $k) => "{$k}={$v}")->implode('  ') : (string) $state)
                    ->limit(70)->wrap()->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        QuarantinedRow::STATUS_OPEN    => 'warning',
                        QuarantinedRow::STATUS_SKIPPED => 'gray',
                        default                        => 'success',
                    }),
                TextColumn::make('created_at')->label('When')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                // Learn-from-resolution: turn a human fix into a reusable tenant alias, so
                // every future batch resolves this value automatically.
                Action::make('resolveAlias')
                    ->label('Resolve → alias')
                    ->icon('heroicon-o-link')
                    ->color('success')
                    ->visible(fn (QuarantinedRow $r) => $r->status === QuarantinedRow::STATUS_OPEN)
                    ->fillForm(fn (QuarantinedRow $r) => [
                        'entity_type' => EntityAlias::TYPE_SKU,
                        'alias'       => (string) (($r->cleansed_data['sku'] ?? $r->raw_data['sku'] ?? '') ?: ''),
                    ])
                    ->form([
                        Select::make('entity_type')->label('Entity')->options([
                            EntityAlias::TYPE_SKU      => 'SKU',
                            EntityAlias::TYPE_STORE    => 'Store / Location',
                            EntityAlias::TYPE_SUPPLIER => 'Supplier',
                        ])->required(),
                        TextInput::make('alias')->label('Inbound value')->required()
                            ->helperText('The messy value in this row.'),
                        TextInput::make('canonical')->label('Canonical value')->required()
                            ->helperText('What it should resolve to. Applied to all future batches.'),
                    ])
                    ->action(function (QuarantinedRow $record, array $data): void {
                        EntityAlias::updateOrCreate(
                            [
                                'tenant_id'   => $record->tenant_id,
                                'entity_type' => $data['entity_type'],
                                'alias'       => mb_strtolower(trim((string) $data['alias'])),
                            ],
                            ['canonical' => trim((string) $data['canonical'])],
                        );
                        $record->update(['status' => QuarantinedRow::STATUS_RESOLVED]);
                        Notification::make()
                            ->title('Alias saved — future rows will resolve automatically')
                            ->success()->send();
                    }),
            ])
            ->filters([
                SelectFilter::make('reason_code')
                    ->label('Reason')
                    ->options(collect(Reasons::LABELS)->all()),
                SelectFilter::make('status')->options([
                    QuarantinedRow::STATUS_OPEN    => 'Open',
                    QuarantinedRow::STATUS_SKIPPED => 'Skipped',
                    QuarantinedRow::STATUS_RESOLVED=> 'Resolved',
                ])->multiple(),
                SelectFilter::make('data_type')
                    ->label('Type')
                    ->multiple()
                    ->options([
                        'sales_transactions' => 'Sales Transactions',
                        'inventory_levels'   => 'Inventory Levels',
                        'products'           => 'Products',
                        'purchase_orders'    => 'Purchase Orders',
                        'stores'             => 'Stores / Locations',
                        'suppliers'          => 'Suppliers',
                        'users'              => 'Users (account setup)',
                        'returns'            => 'Returns / Refunds',
                        'promotions'         => 'Promotions (calendar)',
                        'waste'              => 'Waste / write-offs',
                    ]),
                Filter::make('created_at')
                    ->label('Date')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'],  fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'], fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    // WP3.6: after fixing a rule / alias / product master, re-run the gate.
                    BulkAction::make('rescreen')
                        ->label('Re-screen')
                        ->icon('heroicon-o-arrow-path')
                        ->visible(fn () => (bool) auth()->user()?->canManageImports())
                        ->action(function (Collection $records): void {
                            abort_unless((bool) auth()->user()?->canManageImports(), 403);
                            $r = app(\App\Services\Import\ImportProcessorService::class)->reprocessQuarantined($records, false, auth()->user());
                            Notification::make()->title("{$r['promoted']} row(s) now pass and were loaded; {$r['still']} still quarantined")
                                ->color($r['still'] > 0 ? 'warning' : 'success')->send();
                        }),
                    BulkAction::make('promote')
                        ->label('Promote anyway')
                        ->icon('heroicon-o-arrow-up-circle')
                        ->color('danger')
                        ->visible(fn () => (bool) auth()->user()?->isTenantAdmin())
                        ->requiresConfirmation()
                        ->modalDescription('Loads the selected rows despite their data-quality reason (rows without their key cannot be loaded). This is recorded in the audit log.')
                        ->action(function (Collection $records): void {
                            abort_unless((bool) auth()->user()?->isTenantAdmin(), 403);
                            $r = app(\App\Services\Import\ImportProcessorService::class)->reprocessQuarantined($records, true, auth()->user());
                            Notification::make()->title("{$r['promoted']} row(s) promoted; {$r['still']} could not be loaded")
                                ->color($r['still'] > 0 ? 'warning' : 'success')->send();
                        }),
                    BulkAction::make('skip')
                        ->label('Mark skipped')
                        ->icon('heroicon-o-x-circle')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->update(['status' => QuarantinedRow::STATUS_SKIPPED])),
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Nothing in quarantine')
            ->emptyStateDescription('Rows the firewall rejects (bad keys, unparseable values, duplicates) land here for triage.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuarantinedRows::route('/'),
        ];
    }
}
