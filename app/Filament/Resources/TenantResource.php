<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Models\Tenant;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office';

    protected static \UnitEnum|string|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 1;

    protected static ?string $label = 'Organization';

    protected static ?string $pluralLabel = 'Organizations';

    // This resource manages tenants themselves — do not scope it to any tenant.
    public static function isScopedToTenant(): bool
    {
        return false;
    }

    // Only super-admins may manage organizations.
    public static function canViewAny(): bool
    {
        return auth()->user()?->is_super_admin ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (string $operation, $state, Set $set) {
                    if ($operation === 'create') {
                        $set('slug', Str::slug($state));
                    }
                }),

            TextInput::make('slug')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255)
                ->rules(['alpha_dash'])
                ->helperText('Used in the URL. Only letters, numbers, hyphens and underscores.')
                ->dehydrateStateUsing(fn (string $state) => Str::slug($state)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->searchable(),

                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Users')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit'   => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
