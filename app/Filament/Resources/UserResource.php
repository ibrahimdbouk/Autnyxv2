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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    protected static \UnitEnum|string|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $form): Schema
    {
        $isSuperAdmin = auth()->user()?->is_super_admin ?? false;

        return $form->schema([
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

            Select::make('tenant_id')
                ->label('Organization')
                ->relationship('tenant', 'name')
                ->searchable()
                ->preload()
                ->visible($isSuperAdmin)
                ->default(fn (): ?int => Filament::getTenant()?->id),

            Toggle::make('is_super_admin')
                ->label('Super Admin')
                ->helperText('Super admins can access and manage all organizations.')
                ->visible($isSuperAdmin),
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
                    ->searchable(),

                TextColumn::make('tenant.name')
                    ->label('Organization')
                    ->sortable()
                    ->toggleable(),

                IconColumn::make('is_super_admin')
                    ->label('Super Admin')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

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
