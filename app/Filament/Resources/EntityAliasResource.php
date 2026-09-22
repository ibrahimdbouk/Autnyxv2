<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EntityAliasResource\Pages;
use App\Models\EntityAlias;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Entity aliases — teach the canonicaliser that messy inbound values map to one
 * canonical entity ("Store 001" = "ST001"). See claude/data-quality-firewall.md.
 */
class EntityAliasResource extends Resource
{
    protected static ?string $model = EntityAlias::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-link';

    protected static \UnitEnum|string|null $navigationGroup = 'Data Quality';

    protected static ?string $navigationLabel = 'Entity Aliases';

    protected static ?int $navigationSort = 4;

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
            Select::make('entity_type')
                ->label('Entity')
                ->options([
                    EntityAlias::TYPE_SKU      => 'SKU',
                    EntityAlias::TYPE_STORE    => 'Store / Location',
                    EntityAlias::TYPE_SUPPLIER => 'Supplier',
                ])
                ->required(),
            TextInput::make('alias')
                ->label('Inbound value (alias)')
                ->required()->maxLength(255)
                ->helperText('The messy value as it appears in files. Matched case-insensitively.'),
            TextInput::make('canonical')
                ->label('Canonical value')
                ->required()->maxLength(255)
                ->helperText('What Autnyx should store instead.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('entity_type')->badge()->color('gray')->sortable(),
                TextColumn::make('alias')->searchable()->weight('semibold'),
                TextColumn::make('canonical')->searchable(),
                TextColumn::make('updated_at')->since()->sortable()->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->emptyStateHeading('No aliases yet')
            ->emptyStateDescription('Add an alias to collapse SKU / store / supplier variants to one canonical value.');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListEntityAliases::route('/'),
            'create' => Pages\CreateEntityAlias::route('/create'),
            'edit'   => Pages\EditEntityAlias::route('/{record}/edit'),
        ];
    }
}
