<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Models\Tenant;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
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

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (string $operation, $state, $set) {
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

            Section::make('Anomaly Notifications')
                ->description('Send an email digest when new anomalies are detected. Leave blank to disable.')
                ->collapsed()
                ->schema([
                    TextInput::make('notification_email')
                        ->label('Notification Email')
                        ->email()
                        ->nullable()
                        ->maxLength(255)
                        ->placeholder('ops@yourcompany.com')
                        ->helperText('Leave blank to disable email notifications.'),

                    Toggle::make('notify_on_high')
                        ->label('Notify on High Severity')
                        ->default(true),

                    Toggle::make('notify_on_medium')
                        ->label('Notify on Medium Severity')
                        ->default(false),
                ]),
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

                TextColumn::make('notification_email')
                    ->label('Alert Email')
                    ->placeholder('—')
                    ->toggleable(),

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
