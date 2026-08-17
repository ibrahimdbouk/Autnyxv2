<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    protected static \UnitEnum|string|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 2;

    // ---------- Permissions ----------

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        return $user && ($user->is_super_admin || $user->is_tenant_admin);
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();
        return $user && ($user->is_super_admin || $user->is_tenant_admin);
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        $user = auth()->user();
        return $user && ($user->is_super_admin || $user->is_tenant_admin);
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        // Prevent deleting yourself
        return auth()->id() !== $record->id
            && (auth()->user()?->is_super_admin || auth()->user()?->is_tenant_admin);
    }

    // Scope listing: tenant admins only see users within their tenant
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! auth()->user()?->is_super_admin) {
            $query->where('tenant_id', Filament::getTenant()?->id);
        }

        return $query;
    }

    // ---------- Form ----------

    public static function form(Schema $form): Schema
    {
        $isSuperAdmin   = auth()->user()?->is_super_admin ?? false;
        $isTenantAdmin  = auth()->user()?->is_tenant_admin ?? false;

        return $form->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),

            TextInput::make('email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),

            TextInput::make('password')
                ->password()
                ->revealable()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrateStateUsing(fn (string $state): string => bcrypt($state))
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->label(fn (string $operation): string => $operation === 'create'
                    ? 'Password'
                    : 'New Password (leave blank to keep current)'),

            // Super admin can assign users to any tenant
            Select::make('tenant_id')
                ->label('Organization')
                ->relationship('tenant', 'name')
                ->searchable()
                ->preload()
                ->visible($isSuperAdmin)
                ->default(fn (): ?int => Filament::getTenant()?->id),

            // Tenant admins and super admins can promote users to tenant admin
            Toggle::make('is_tenant_admin')
                ->label('Tenant Admin')
                ->helperText('Tenant admins can manage users and imports within their organization.')
                ->visible($isSuperAdmin || $isTenantAdmin)
                ->default(false),

            // Only super admins can grant super admin status
            Toggle::make('is_super_admin')
                ->label('Super Admin')
                ->helperText('Super admins can access and manage all organizations.')
                ->visible($isSuperAdmin)
                ->default(false),
        ]);
    }

    // ---------- Table ----------

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->searchable(),

                TextColumn::make('tenant.name')
                    ->label('Organization')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('role')
                    ->label('Role')
                    ->getStateUsing(fn (User $record): string => match (true) {
                        $record->is_super_admin  => 'Super Admin',
                        $record->is_tenant_admin => 'Tenant Admin',
                        default                  => 'User',
                    })
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Super Admin'  => 'danger',
                        'Tenant Admin' => 'warning',
                        default        => 'gray',
                    }),

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
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
