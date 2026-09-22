<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CleansingRuleResource\Pages;
use App\Models\CleansingRule;
use App\Models\Import;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Per-tenant cleansing overrides on top of the firewall's built-in defaults. One row
 * = one transform for one field, applied in ordinal order. See
 * claude/data-quality-firewall.md.
 */
class CleansingRuleResource extends Resource
{
    protected static ?string $model = CleansingRule::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-sparkles';

    protected static \UnitEnum|string|null $navigationGroup = 'Data Quality';

    protected static ?string $navigationLabel = 'Cleansing Rules';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ($user->is_super_admin || $user->is_tenant_admin));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id);
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Select::make('data_type')
                ->label('Data type')
                ->options(Import::dataTypeLabels())
                ->required()->searchable(),
            TextInput::make('field')
                ->label('Canonical field')
                ->required()->maxLength(60)
                ->helperText('e.g. sku, date, quantity, location — the canonical field name.'),
            Select::make('rule_type')
                ->label('Transform')
                ->options(CleansingRule::TYPES)
                ->required(),
            KeyValue::make('params')
                ->label('Parameters')
                ->helperText('e.g. default_if_blank → value; regex_replace → pattern, replacement; value_map → from, to.')
                ->columnSpanFull(),
            TextInput::make('ordinal')->label('Order')->numeric()->default(100)->minValue(1)->maxValue(999),
            Toggle::make('enabled')->default(true)->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('data_type')->badge()->color('gray')->sortable(),
                TextColumn::make('field')->searchable()->weight('semibold'),
                TextColumn::make('rule_type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => CleansingRule::TYPES[$state] ?? $state),
                TextColumn::make('ordinal')->label('Order')->numeric()->sortable(),
                IconColumn::make('enabled')->boolean(),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(),
            ])
            ->defaultSort('data_type')
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->emptyStateHeading('No custom rules')
            ->emptyStateDescription('The firewall already applies sensible defaults. Add a rule only to override or extend them.');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCleansingRules::route('/'),
            'create' => Pages\CreateCleansingRule::route('/create'),
            'edit'   => Pages\EditCleansingRule::route('/{record}/edit'),
        ];
    }
}
