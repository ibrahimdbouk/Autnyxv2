<?php

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\UserResource\Pages;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * 2a — manage users from the super-admin /ops control plane: create a user and
 * assign them to a specific tenant with a role. Scoped to TENANT users — super
 * admins / the owner are platform accounts, protected here (visible but not
 * editable), and this resource never mints super admins (that stays owner-only,
 * enforced in User::booted()).
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Manage Users';

    protected static ?int $navigationSort = 3;

    const ROLE_TENANT_ADMIN = 'tenant_admin';
    const ROLE_USER = 'user';

    const ROLES = [
        self::ROLE_TENANT_ADMIN => 'Tenant Admin',
        self::ROLE_USER         => 'User',
    ];

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    /** Only tenant-scoped users are editable here; platform (super-admin) accounts are not. */
    public static function canEdit(Model $record): bool
    {
        return (bool) auth()->user()?->is_super_admin && ! $record->is_super_admin;
    }

    public static function canDelete(Model $record): bool
    {
        return (bool) auth()->user()?->is_super_admin && ! $record->is_super_admin && ! $record->isOwner();
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('User')->schema([
                TextInput::make('name')->required()->maxLength(255),

                TextInput::make('email')
                    ->email()->required()->maxLength(255)
                    ->unique(ignoreRecord: true),

                Select::make('tenant_id')
                    ->label('Tenant')
                    ->options(fn () => Tenant::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->helperText('Which tenant this user belongs to.'),

                Select::make('role')
                    ->options(self::ROLES)
                    ->default(self::ROLE_USER)
                    ->required()
                    ->helperText('Tenant Admin can manage their tenant’s users, imports and settings.'),

                TextInput::make('password')
                    ->password()->revealable()
                    ->minLength(10)->maxLength(255)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText('Share securely. Leave blank when editing to keep the current password.'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('tenant.name')->label('Tenant')->searchable()->sortable()->placeholder('—'),
                TextColumn::make('role')->label('Role')->badge()
                    ->state(fn (User $record) => $record->roleLabel())
                    ->color(fn (User $record) => $record->is_super_admin ? 'danger' : ($record->is_tenant_admin ? 'warning' : 'gray')),
                TextColumn::make('last_login_at')->label('Last login')->since()->placeholder('Never')->sortable()->toggleable(),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
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
