<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DepartmentResource\Pages;
use App\Models\Department;
use App\Services\Platform\HierarchySync;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform core — the tenant's departments (Grocery, Fresh…) and sections
 * under them (Grocery → Beverages). Shared by every app: store zones and work
 * sit in a department. Departments named in the product file are added
 * automatically; the tenant owns and edits the list from then on.
 */
class DepartmentResource extends Resource
{
    protected static ?string $model = Department::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static \UnitEnum|string|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Departments';

    protected static ?int $navigationSort = 12;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canManageUsers();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id)
            ->with('parent')->withCount(['children', 'zones'])
            ->orderByRaw('COALESCE(departments.parent_id, departments.id), departments.parent_id IS NOT NULL, departments.sort, departments.name');
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Department')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(120)
                    ->rules([fn (?Department $record, \Filament\Schemas\Components\Utilities\Get $get) => function (string $attribute, $value, \Closure $fail) use ($record, $get) {
                        $taken = Department::where('tenant_id', Filament::getTenant()?->id)
                            ->where(fn ($q) => ($p = $get('parent_id')) ? $q->where('parent_id', $p) : $q->whereNull('parent_id'))
                            ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                            ->when($record?->exists, fn ($q) => $q->whereKeyNot($record->getKey()))->exists();
                        if ($taken) {
                            $fail('That name is already used at this level.');
                        }
                    }]),
                Select::make('parent_id')->label('Section of')->placeholder('— a top-level department —')
                    ->options(fn (?Department $record) => Department::where('tenant_id', Filament::getTenant()?->id)->whereNull('parent_id')
                        ->when($record?->exists, fn ($q) => $q->whereKeyNot($record->getKey()))->orderBy('name')->pluck('name', 'id')->all())
                    ->disabled(fn (?Department $record) => $record?->exists && $record->children()->exists())
                    ->helperText('Leave empty for a department; pick one to make this a section of it (e.g. Beverages in Grocery).'),
                TextInput::make('code')->maxLength(40),
                TextInput::make('sort')->integer()->minValue(0)->maxValue(9999)->default(0),
                TagsInput::make('categories')->label('Product categories it covers')->columnSpanFull()
                    ->placeholder('e.g. Soft Drinks, Water, Juices')
                    ->helperText('As they appear in your product file (department, category or subcategory). Used to place a product in this department.'),
                Toggle::make('active')->default(true)->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('semibold')
                    ->formatStateUsing(fn ($state, Department $d) => $d->parent ? $d->parent->name . ' › ' . $state : $state),
                TextColumn::make('code')->placeholder('—')->toggleable(),
                TextColumn::make('categories')->label('Categories')->badge()->limitList(4)->placeholder('—'),
                TextColumn::make('children_count')->label('Sections')->alignCenter(),
                TextColumn::make('zones_count')->label('Zones')->alignCenter(),
                TextColumn::make('source')->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => $state === Department::SOURCE_PRODUCTS ? 'From products' : 'Added here'),
                IconColumn::make('active')->boolean(),
            ])
            ->filters([TernaryFilter::make('active')])
            ->actions([EditAction::make(), DeleteAction::make()])
            ->headerActions([
                Action::make('adopt')->label('Add departments from the product file')->icon('heroicon-o-arrow-down-tray')->color('gray')
                    ->action(function () {
                        $n = app(HierarchySync::class)->syncTenant((int) Filament::getTenant()?->id)['departments'] ?? 0;
                        Notification::make()->title($n ? "{$n} department(s) added" : 'Every department in the product file is already here')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No departments yet')
            ->emptyStateDescription('Add them here, or load a product file with a Department column and they appear on their own.');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDepartments::route('/'),
            'create' => Pages\CreateDepartment::route('/create'),
            'edit'   => Pages\EditDepartment::route('/{record}/edit'),
        ];
    }
}
