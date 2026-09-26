<?php

namespace App\Filament\Resources\StoreResource\RelationManagers;

use App\Models\Department;
use App\Models\Store;
use App\Models\StoreZone;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Platform core — the places inside a store (aisles, endcaps, chillers…),
 * each optionally in a department. Work can point at a zone. "Copy to other
 * stores" sets up a chain of similar layouts in one go.
 */
class ZonesRelationManager extends RelationManager
{
    protected static string $relationship = 'zones';

    protected static ?string $title = 'Zones in this store';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->canManageImports();
    }

    private static function departmentOptions(): array
    {
        return Department::where('tenant_id', Filament::getTenant()?->id)->where('active', true)
            ->with('parent')->orderByRaw('COALESCE(parent_id, id), parent_id IS NOT NULL, sort, name')->get()
            ->mapWithKeys(fn (Department $d) => [$d->id => $d->fullName()])->all();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->schema([
            TextInput::make('name')->required()->maxLength(120)->placeholder('e.g. Endcap 3, Aisle 7, Dairy chiller')
                ->rules([fn (?StoreZone $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                    $taken = StoreZone::where('store_id', $this->getOwnerRecord()->getKey())
                        ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                        ->when($record?->exists, fn ($q) => $q->whereKeyNot($record->getKey()))->exists();
                    if ($taken) {
                        $fail('This store already has a zone with that name.');
                    }
                }]),
            Select::make('zone_type')->label('Type')->options(StoreZone::TYPES)->default('aisle')->required(),
            Select::make('department_id')->label('Department')->options(fn () => self::departmentOptions())->searchable()
                ->placeholder('None'),
            TextInput::make('code')->maxLength(40)->placeholder('Optional, e.g. A07-E3'),
            TextInput::make('sort')->integer()->minValue(0)->maxValue(9999)->default(0),
            Toggle::make('active')->default(true)->inline(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->weight('semibold'),
                TextColumn::make('zone_type')->label('Type')->badge()->formatStateUsing(fn ($state) => StoreZone::TYPES[$state] ?? $state),
                TextColumn::make('department.name')->label('Department')->placeholder('—'),
                TextColumn::make('code')->placeholder('—')->toggleable(),
                IconColumn::make('active')->boolean(),
            ])
            ->defaultSort('sort')
            ->filters([
                SelectFilter::make('zone_type')->label('Type')->options(StoreZone::TYPES),
            ])
            ->headerActions([
                CreateAction::make()->label('Add zone')
                    ->mutateDataUsing(fn (array $data) => $data + ['tenant_id' => $this->getOwnerRecord()->tenant_id]),
                Action::make('copyZones')->label('Copy to other stores')->icon('heroicon-o-document-duplicate')->color('gray')
                    ->visible(fn () => $this->getOwnerRecord()->zones()->exists())
                    ->schema([
                        Select::make('stores')->label('Copy this store\'s zones to')->multiple()->searchable()->required()
                            ->options(fn () => Store::where('tenant_id', $this->getOwnerRecord()->tenant_id)
                                ->whereKeyNot($this->getOwnerRecord()->getKey())->orderBy('name')->pluck('name', 'id')->all()),
                    ])
                    ->action(function (array $data) {
                        $n = self::copyZones($this->getOwnerRecord(), array_map('intval', (array) $data['stores']));
                        Notification::make()->title($n ? "{$n} zone(s) added" : 'Those stores already had these zones')->success()->send();
                    }),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No zones yet')
            ->emptyStateDescription('Add the places work is pointed at: aisles, endcaps, promotion displays, chillers, the backroom.');
    }

    /** Copy every zone of $from to the given stores (same tenant); zones a store already has by name are skipped. */
    public static function copyZones(Store $from, array $storeIds): int
    {
        $targets = Store::where('tenant_id', $from->tenant_id)->whereIn('id', $storeIds)->whereKeyNot($from->getKey())->pluck('id');
        $zones = $from->zones()->get();
        $n = 0;
        foreach ($targets as $storeId) {
            $have = StoreZone::where('store_id', $storeId)->pluck('name')->map(fn ($v) => mb_strtolower($v))->all();
            foreach ($zones as $z) {
                if (in_array(mb_strtolower($z->name), $have, true)) {
                    continue;
                }
                StoreZone::create([
                    'tenant_id' => $from->tenant_id, 'store_id' => $storeId, 'department_id' => $z->department_id,
                    'code' => $z->code, 'name' => $z->name, 'zone_type' => $z->zone_type, 'sort' => $z->sort, 'active' => $z->active,
                ]);
                $n++;
            }
        }

        return $n;
    }
}
