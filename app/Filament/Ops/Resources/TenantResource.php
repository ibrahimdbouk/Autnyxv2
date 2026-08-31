<?php

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\TenantResource\Pages;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 2b — create and manage tenants from the control plane. On create it also
 * provisions the tenant's first admin (via TenantProvisioner). Lives only in the
 * super-admin /ops panel, which is already gated; the checks here are belt-and-
 * suspenders.
 */
class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'Manage Tenants';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return (bool) (auth()->user()?->is_super_admin);
    }

    public static function canCreate(): bool
    {
        return (bool) (auth()->user()?->is_super_admin);
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return (bool) (auth()->user()?->is_super_admin);
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Organisation')->schema([
                TextInput::make('name')->required()->maxLength(255),

                TextInput::make('slug')
                    ->helperText('Used in the URL (/admin/{slug}). Leave blank to generate from the name.')
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),

                Select::make('plan')
                    ->options(Tenant::PLAN_LABELS)
                    ->default(Tenant::PLAN_TRIAL)
                    ->required(),

                Select::make('status')
                    ->options([
                        Tenant::STATUS_ACTIVE    => 'Active',
                        Tenant::STATUS_SUSPENDED => 'Suspended',
                    ])
                    ->default(Tenant::STATUS_ACTIVE)
                    ->required(),

                TextInput::make('currency')
                    ->default('USD')
                    ->helperText('ISO code, e.g. USD, AED, EUR.')
                    ->maxLength(3),
            ])->columns(2),

            Section::make('Apps')
                ->description('Which Autnyx apps this tenant can use. Root-Cause is the built app; others become available as they ship.')
                ->schema([
                    CheckboxList::make('apps')
                        ->hiddenLabel()
                        ->options(Tenant::APP_LABELS)
                        ->descriptions([
                            Tenant::APP_ROOT_CAUSE     => 'Detection, investigation and recovery (live).',
                            Tenant::APP_ASSORTMENT     => 'Distribution-gap intelligence (in development).',
                            Tenant::APP_TASK_EXECUTION => 'Cross-app task queue and outcomes (in development).',
                        ])
                        ->default(Tenant::DEFAULT_APPS)
                        ->columns(1),
                ]),

            Section::make('First administrator')
                ->description('Create the tenant’s first admin account. They can add the rest of their team.')
                ->visibleOn('create')
                ->schema([
                    TextInput::make('admin_name')->label('Name')->maxLength(255),
                    TextInput::make('admin_email')->label('Email')->email()->maxLength(255),
                    TextInput::make('admin_password')
                        ->label('Temporary password')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->maxLength(255)
                        ->helperText('Share securely. Leave blank to skip creating an admin now.'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (Tenant $r) => $r->slug),
                TextColumn::make('plan')->badge()
                    ->formatStateUsing(fn (Tenant $r) => $r->planLabel())
                    ->color('warning'),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => $state === Tenant::STATUS_ACTIVE ? 'success' : 'danger'),
                TextColumn::make('apps_label')->label('Apps')->badge()->color('primary')
                    ->state(fn (Tenant $record) => collect($record->enabledApps())
                        ->map(fn (string $app) => match ($app) {
                            Tenant::APP_ROOT_CAUSE     => 'Root-Cause',
                            Tenant::APP_ASSORTMENT     => 'Assortment',
                            Tenant::APP_TASK_EXECUTION => 'Tasks',
                            default                    => $app,
                        })
                        ->all()),
                TextColumn::make('users_count')->counts('users')->label('Users'),
                TextColumn::make('created_at')->since()->label('Created')->sortable(),
            ])
            ->actions([
                Action::make('impersonate')
                    ->label('Enter')
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->url(fn (Tenant $record) => route('ops.impersonate', ['tenant' => $record->id]))
                    ->requiresConfirmation()
                    ->modalHeading('Enter tenant')
                    ->modalDescription(fn (Tenant $record) => 'Sign in as an admin of ' . $record->name . '? Your actions will be recorded in the audit log.'),
            ])
            ->defaultSort('created_at', 'desc');
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
