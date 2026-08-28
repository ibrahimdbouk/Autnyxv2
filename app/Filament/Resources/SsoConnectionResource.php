<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SsoConnectionResource\Pages;
use App\Models\SsoConnection;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 1b — configure this tenant's OIDC single sign-on. Admin-only, tenant-scoped,
 * one connection per organisation.
 */
class SsoConnectionResource extends Resource
{
    protected static ?string $model = SsoConnection::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-key';

    protected static \UnitEnum|string|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Single Sign-On';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return auth()->user()?->canManageUsers() ?? false;
    }

    public static function canCreate(): bool
    {
        if (! (auth()->user()?->canManageUsers() ?? false)) {
            return false;
        }

        // One connection per tenant.
        $tenantId = Filament::getTenant()?->id;

        return $tenantId !== null
            && ! SsoConnection::where('tenant_id', $tenantId)->exists();
    }

    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->canManageUsers() ?? false;
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return auth()->user()?->canManageUsers() ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = Filament::getTenant()?->id;

        return parent::getEloquentQuery()->where('tenant_id', $tenantId);
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Provider')
                ->description('Connect your identity provider (Okta, Azure AD/Entra, Google Workspace, …) over OpenID Connect.')
                ->schema([
                    Toggle::make('enabled')
                        ->label('Enabled')
                        ->helperText('When off, single sign-on is unavailable for this organisation.')
                        ->default(false),

                    TextInput::make('label')
                        ->label('Display name')
                        ->default('Single sign-on')
                        ->maxLength(255),

                    TextInput::make('issuer')
                        ->label('Issuer URL')
                        ->required()
                        ->url()
                        ->helperText('e.g. https://your-org.okta.com — discovery is read from {issuer}/.well-known/openid-configuration.')
                        ->maxLength(255),

                    TextInput::make('client_id')
                        ->label('Client ID')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('client_secret')
                        ->label('Client secret')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation) => $operation === 'create')
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->helperText(fn (string $operation) => $operation === 'edit'
                            ? 'Leave blank to keep the stored secret. Stored encrypted.'
                            : 'Stored encrypted.')
                        ->maxLength(500),

                    TextInput::make('scopes')
                        ->label('Scopes')
                        ->default('openid email profile')
                        ->helperText('Space-separated. "openid" is always included.')
                        ->maxLength(255),

                    Placeholder::make('callback_url')
                        ->label('Redirect / callback URL (register this with your IdP)')
                        ->content(fn () => route('sso.callback', ['tenant' => Filament::getTenant()?->slug])),
                ])->columns(2),

            Section::make('Provisioning')
                ->description('How SSO users map to accounts in this organisation.')
                ->schema([
                    TextInput::make('email_claim')->default('email')->required()->maxLength(255),
                    TextInput::make('name_claim')->default('name')->required()->maxLength(255),

                    Toggle::make('jit_provisioning')
                        ->label('Create accounts on first sign-in (JIT)')
                        ->helperText('When off, only users who already exist in this organisation can sign in.')
                        ->default(true),

                    Textarea::make('allowed_domains')
                        ->label('Allowed email domains')
                        ->helperText('One per line, e.g. acme.com. Required for email-based org discovery; leave blank to allow any domain the IdP returns.')
                        ->rows(3)
                        ->formatStateUsing(fn ($state) => is_array($state) ? implode("\n", $state) : (string) ($state ?? ''))
                        ->dehydrateStateUsing(fn ($state) => collect(preg_split('/[\s,]+/', (string) $state))
                            ->map(fn ($d) => strtolower(trim($d)))
                            ->filter()
                            ->unique()
                            ->values()
                            ->all()),

                    TextInput::make('admin_group_claim')
                        ->label('Admin group claim')
                        ->helperText('Optional. The claim carrying group membership, e.g. "groups".')
                        ->maxLength(255),

                    TextInput::make('admin_group_value')
                        ->label('Admin group value')
                        ->helperText('Optional. Membership in this group grants Tenant Admin.')
                        ->maxLength(255),
                ])->columns(2),

            Section::make('Advanced — endpoint overrides')
                ->description('Only needed when the IdP has no discovery document. Blank fields are resolved from the issuer.')
                ->collapsed()
                ->schema([
                    TextInput::make('discovery_url')->label('Discovery URL')->url()->maxLength(255),
                    TextInput::make('authorization_endpoint')->url()->maxLength(255),
                    TextInput::make('token_endpoint')->url()->maxLength(255),
                    TextInput::make('userinfo_endpoint')->url()->maxLength(255),
                    TextInput::make('jwks_uri')->url()->maxLength(255),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Provider')->searchable(),
                TextColumn::make('issuer')->limit(40)->toggleable(),
                IconColumn::make('enabled')->boolean()->label('Enabled'),
                TextColumn::make('updated_at')->since()->label('Updated')->toggleable(),
            ])
            ->emptyStateHeading('No single sign-on configured')
            ->emptyStateDescription('Add your identity provider to let this organisation sign in with SSO.');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSsoConnections::route('/'),
            'create' => Pages\CreateSsoConnection::route('/create'),
            'edit'   => Pages\EditSsoConnection::route('/{record}/edit'),
        ];
    }
}
