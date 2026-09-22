<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ImportQualityResource\Pages;
use App\Models\ImportQuality;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Data-quality summary per import — the score, promote/quarantine/cleanse counts and
 * the re-upload flag produced by the firewall. Read-only. See
 * claude/data-quality-firewall.md.
 */
class ImportQualityResource extends Resource
{
    protected static ?string $model = ImportQuality::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-shield-check';

    protected static \UnitEnum|string|null $navigationGroup = 'Data Quality';

    protected static ?string $navigationLabel = 'Import Quality';

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ($user->is_super_admin || $user->is_tenant_admin));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('import_id')->label('Import')->sortable(),
                TextColumn::make('data_type')->badge()->color('gray'),
                TextColumn::make('quality_pct')
                    ->label('Quality')
                    ->badge()
                    ->getStateUsing(fn (ImportQuality $r) => $r->qualityScore() . '%')
                    ->color(fn (ImportQuality $r) => $r->qualityColor()),
                TextColumn::make('rows_promoted')->label('Promoted')->numeric()->color('success'),
                TextColumn::make('rows_quarantined')->label('Quarantined')->numeric()->color('danger'),
                TextColumn::make('rows_cleansed')->label('Cleansed')->numeric()->color('warning'),
                IconColumn::make('is_duplicate_file')->label('Re-upload')->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')->trueColor('warning')
                    ->falseIcon('heroicon-o-minus')->falseColor('gray'),
                TextColumn::make('created_at')->label('When')->since()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No import quality yet')
            ->emptyStateDescription('Quality summaries appear here after each import runs through the firewall.')
            ->emptyStateIcon('heroicon-o-shield-check');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportQuality::route('/'),
        ];
    }
}
