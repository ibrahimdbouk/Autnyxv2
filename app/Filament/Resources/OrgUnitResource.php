<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrgUnitResource\Pages;
use App\Models\LocationNode;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Platform core — Regions & Areas. The tree itself comes from each store's
 * Region and Area (store form or store file); here admins see it and assign
 * who manages each region and area. Those managers are the escalation chain
 * above the store manager, and an area manager's stores are the ones under
 * the regions / areas they manage.
 */
class OrgUnitResource extends Resource
{
    protected static ?string $model = LocationNode::class;

    protected static ?string $slug = 'regions-and-areas';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-map';

    protected static \UnitEnum|string|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Regions & Areas';

    protected static ?string $modelLabel = 'region or area';

    protected static ?string $pluralModelLabel = 'regions and areas';

    protected static ?int $navigationSort = 11;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canManageUsers();
    }

    public static function canCreate(): bool
    {
        return false; // built from the stores' Region and Area
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false; // an empty region / area disappears on its own once nobody manages it
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id)
            ->whereIn('type', [LocationNode::TYPE_REGION, LocationNode::TYPE_AREA])
            ->with(['parent', 'managers'])
            ->orderByRaw("COALESCE(location_nodes.parent_id, location_nodes.id), location_nodes.type = 'area', location_nodes.name");
    }

    /** Active people of this tenant who can be put in charge of a region or area. */
    public static function managerOptions(): array
    {
        return User::active()->where('tenant_id', Filament::getTenant()?->id)->where('is_super_admin', false)
            ->orderBy('name')->get()
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name . ($u->orgRoleLabel() ? ' — ' . $u->orgRoleLabel() : '')])->all();
    }

    public static function setManagers(LocationNode $node, array $userIds): void
    {
        abort_unless((int) $node->tenant_id === (int) Filament::getTenant()?->id && static::canAccess(), 403);
        $valid = User::where('tenant_id', $node->tenant_id)->where('is_super_admin', false)->whereIn('id', array_map('intval', $userIds))->pluck('id');
        $node->managers()->sync($valid->mapWithKeys(fn ($id) => [(int) $id => ['tenant_id' => $node->tenant_id]])->all());
    }

    /** Stores under a region / area. */
    public static function storeCount(LocationNode $node): int
    {
        return (int) (DB::selectOne(
            "WITH RECURSIVE sub AS (SELECT id FROM location_nodes WHERE id = ?
                 UNION SELECT c.id FROM location_nodes c JOIN sub ON c.parent_id = sub.id)
             SELECT COUNT(*) AS n FROM location_nodes WHERE id IN (SELECT id FROM sub) AND type = 'store' AND store_id IS NOT NULL",
            [$node->id]
        )->n ?? 0);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('semibold')
                    ->formatStateUsing(fn ($state, LocationNode $n) => $n->type === LocationNode::TYPE_AREA && $n->parent ? $n->parent->name . ' › ' . $state : $state),
                TextColumn::make('type')->badge()->color(fn ($state) => $state === LocationNode::TYPE_REGION ? 'primary' : 'gray')
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
                TextColumn::make('stores')->label('Stores')->alignCenter()->state(fn (LocationNode $n) => static::storeCount($n))
                    ->color(fn ($state) => $state ? null : 'danger')
                    ->tooltip(fn ($state) => $state ? null : 'No stores point at it any more; it stays while someone is assigned to manage it.'),
                TextColumn::make('managers.name')->label('Managed by')->badge()->placeholder('Nobody yet')->limitList(4),
            ])
            ->filters([
                SelectFilter::make('type')->options([LocationNode::TYPE_REGION => 'Region', LocationNode::TYPE_AREA => 'Area']),
            ])
            ->actions([
                \Filament\Actions\Action::make('managers')->label('Managers')->icon('heroicon-o-user-group')
                    ->modalHeading(fn (LocationNode $n) => 'Who manages ' . $n->name)
                    ->fillForm(fn (LocationNode $n) => ['managers' => $n->managers()->pluck('users.id')->all()])
                    ->schema([
                        Select::make('managers')->label('Managed by')->multiple()->searchable()
                            ->options(fn () => static::managerOptions())
                            ->helperText('Usually area or regional managers. They see the stores underneath and receive what escalates past the store manager.'),
                    ])
                    ->action(fn (LocationNode $n, array $data) => static::setManagers($n, (array) ($data['managers'] ?? []))),
            ])
            ->emptyStateHeading('No regions or areas yet')
            ->emptyStateDescription('Give your stores a Region (and optionally an Area): on the store form or with a Region / Area column in the store file. The structure appears here on its own.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrgUnits::route('/'),
        ];
    }
}
