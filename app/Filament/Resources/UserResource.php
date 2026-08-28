<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Support\Screens\ScreenRegistry;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    protected static \UnitEnum|string|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Users';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->canManageUsers() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canManageUsers() ?? false;
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        $user = auth()->user();
        if (! $user) return false;
        // Only the owner can edit the owner account.
        if ($record->isOwner() && ! $user->isOwner()) return false;

        return $user->canManageUsers();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        $user = auth()->user();
        if (! $user || $record->id === $user->id) return false;
        // The owner account is protected — nobody can delete it.
        if ($record->isOwner()) return false;
        // Super admins can only be removed by other super admins.
        if ($record->is_super_admin && ! $user->is_super_admin) return false;

        return $user->canManageUsers();
    }

    /**
     * Scope user management to the current tenant, so a tenant admin only ever
     * sees and manages users within their own organisation.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $tenantId = Filament::getTenant()?->id;

        return $tenantId ? $query->where('tenant_id', $tenantId) : $query;
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Account Details')->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
            ])->columns(2),

            Section::make('Password')->schema([
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->minLength(8)
                    ->dehydrateStateUsing(fn (?string $state) => $state ? Hash::make($state) : null)
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit'
                        ? 'Leave blank to keep the current password.'
                        : null),
            ]),

            Section::make('Role')->schema([
                Toggle::make('is_tenant_admin')
                    ->label('Tenant Admin')
                    ->helperText('Tenant admins can manage users, imports, and settings within this organisation — and see every screen.')
                    ->default(false)
                    ->live()
                    ->visible(fn () => !auth()->user()?->is_super_admin
                        ? true  // tenant admins can toggle this
                        : true),

                Toggle::make('is_super_admin')
                    ->label('Super Admin')
                    ->helperText('Super admins have full access to all organisations and the control plane. Only the owner can grant this.')
                    ->default(false)
                    ->live()
                    ->disabled(fn (?User $record) => $record?->isOwner() ?? false) // owner stays super
                    ->visible(fn () => auth()->user()?->isOwner() ?? false),
            ]),

            // 1a — screen visibility. Only meaningful for a plain "user"; admins
            // always see everything, so this is hidden the moment either admin
            // toggle is on. Tick the screens this user may see; unticking all
            // leaves them with just the Dashboard.
            Section::make('Screen Access')
                ->description('Choose which screens this user can see. Admins always see everything.')
                ->schema([
                    CheckboxList::make('visible_screens')
                        ->hiddenLabel()
                        ->options(ScreenRegistry::options())
                        ->default(ScreenRegistry::keys())
                        ->columns(3)
                        ->bulkToggleable()
                        ->gridDirection('row'),
                ])
                ->visible(fn (Get $get): bool => ! $get('is_tenant_admin') && ! $get('is_super_admin')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->getStateUsing(fn (User $record): string => $record->roleLabel())
                    ->color(fn (string $state): string => match ($state) {
                        'Super Admin'   => 'danger',
                        'Tenant Admin'  => 'warning',
                        default         => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No users yet')
            ->emptyStateDescription('Add your first team member using the button above.');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
