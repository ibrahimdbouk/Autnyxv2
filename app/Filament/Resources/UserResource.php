<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Support\Screens\ScreenRegistry;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
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
        // WP1.3 (audit H8): a tenant admin must never be able to reset a super
        // admin's password/email (that would hand them /ops and every tenant).
        if ($record->is_super_admin && ! $user->is_super_admin) return false;

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
                    ->rule(\Illuminate\Validation\Rules\Password::defaults())
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

            // Platform core — the operating role ladder (separate from admin rights)
            // and the person's working language.
            Section::make('Position')
                ->description('Where this person sits in the operation. It decides which stores they work in and who work escalates to: store manager → area manager → regional manager → head office.')
                ->columns(2)
                ->schema([
                    \Filament\Forms\Components\Select::make('org_role')
                        ->label('Operating role')
                        ->options(User::ORG_ROLES)
                        ->placeholder('Not set')
                        ->live()
                        ->helperText('Store roles see only their linked stores; area managers the stores in the regions / areas they manage.'),
                    \Filament\Forms\Components\Select::make('locale')
                        ->label('Language')
                        ->options(User::LOCALES)
                        ->placeholder('English'),
                    \Filament\Forms\Components\Select::make('managed_nodes')
                        ->label('Manages these regions / areas')
                        ->multiple()
                        ->searchable()
                        ->options(fn () => \App\Services\Org\OrgDirectory::nodeOptions((int) Filament::getTenant()?->id))
                        ->visible(fn (Get $get) => in_array($get('org_role'), [User::ORG_AREA_MANAGER, User::ORG_HQ], true))
                        ->helperText('Regions and areas come from the stores\' Region and Area.')
                        ->columnSpanFull(),
                ]),

            // W12 — the store(s) this person runs: they get a daily digest of those
            // stores only, with a login-free store sheet to confirm findings and count.
            Section::make('Stores')
                ->description('Link a store manager to their store(s). They get a daily digest of those stores only — findings to confirm and stock to count — by e-mail (and Teams when connected).')
                ->schema([
                    \Filament\Forms\Components\Select::make('stores')
                        ->label('Runs these stores')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->relationship('stores', 'name', fn (Builder $query) => $query->where('stores.tenant_id', Filament::getTenant()?->id))
                        ->pivotData(fn () => ['tenant_id' => Filament::getTenant()?->id]),
                    Toggle::make('store_digest')
                        ->label('Send the daily store digest')
                        ->default(true)
                        ->inline(false),
                ])->columns(2),

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

                TextColumn::make('org_role')
                    ->label('Position')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state) => User::ORG_ROLES[$state] ?? $state)
                    ->placeholder('—'),

                TextColumn::make('deactivated_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (User $record) => $record->deactivated_at ? 'Deactivated' : 'Active')
                    ->color(fn (string $state) => $state === 'Active' ? 'success' : 'danger')
                    ->toggleable(),

                TextColumn::make('stores.name')
                    ->label('Stores')
                    ->badge()
                    ->limitList(3)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('org_role')
                    ->label('Position')
                    ->options(User::ORG_ROLES),

                TernaryFilter::make('active')
                    ->label('Status')
                    ->trueLabel('Active')
                    ->falseLabel('Deactivated')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $q) => $q->whereNull('deactivated_at'),
                        false: fn (Builder $q) => $q->whereNotNull('deactivated_at'),
                        blank: fn (Builder $q) => $q,
                    ),

                TernaryFilter::make('is_super_admin')
                    ->label('Super Admin'),

                TernaryFilter::make('is_tenant_admin')
                    ->label('Tenant Admin'),

                Filter::make('created_at')
                    ->label('Joined')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'],  fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'], fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\Action::make('deactivate')
                    ->label('Deactivate')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('For people who have left. They can no longer sign in, receive nothing, and their signed links and phones stop working. Everything they did stays attributed to them; you can reactivate them later.')
                    ->visible(fn (User $record) => $record->deactivated_at === null && static::canEdit($record)
                        && (int) $record->id !== (int) auth()->id() && ! $record->is_super_admin && ! $record->isOwner())
                    ->action(function (User $record) {
                        abort_unless(static::canEdit($record), 403);
                        app(\App\Services\Org\UserLifecycle::class)->deactivate($record, auth()->user());
                        \Filament\Notifications\Notification::make()->title($record->name . ' deactivated')->success()->send();
                    }),
                \Filament\Actions\Action::make('reactivate')
                    ->label('Reactivate')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (User $record) => $record->deactivated_at !== null && static::canEdit($record))
                    ->action(function (User $record) {
                        abort_unless(static::canEdit($record), 403);
                        app(\App\Services\Org\UserLifecycle::class)->reactivate($record, auth()->user());
                        \Filament\Notifications\Notification::make()->title($record->name . ' reactivated')->success()->send();
                    }),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('No users yet')
            ->emptyStateDescription('Add your first team member using the button above.');
    }

    /** Platform core: the regions / areas a person manages (from the user form), this tenant's only. */
    public static function syncManagedNodes(User $user, ?array $nodeIds): void
    {
        if ($nodeIds === null) {
            return;
        }
        $tenantId = (int) $user->tenant_id;
        $valid = \App\Models\LocationNode::where('tenant_id', $tenantId)
            ->whereIn('type', [\App\Models\LocationNode::TYPE_REGION, \App\Models\LocationNode::TYPE_AREA])
            ->whereIn('id', array_map('intval', $nodeIds))->pluck('id');
        $user->managedNodes()->sync($valid->mapWithKeys(fn ($id) => [(int) $id => ['tenant_id' => $tenantId]])->all());
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
