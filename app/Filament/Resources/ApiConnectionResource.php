<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApiConnectionResource\Pages;
use App\Models\ApiConnection;
use App\Models\Import;
use App\Services\Integrations\ApiPollService;
use App\Services\Integrations\ConnectorRegistry;
use App\Services\Integrations\Profiles;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * ApiConnectionResource — configure API pulls from source systems (SAP S/4HANA,
 * Dynamics, Oracle, Shopify, Blue Yonder, RELEX, Slimstock, or any REST source).
 * Feeds map an endpoint to an import type; a scheduled poller (api:poll, hourly)
 * runs each through the standard import pipeline. Admin-gated. Secrets encrypted.
 *
 * See claude/api-integration-library.md.
 */
class ApiConnectionResource extends Resource
{
    protected static ?string $model = ApiConnection::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-bolt';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'API Connections';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ($user->is_super_admin || $user->is_tenant_admin));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Filament::getTenant()?->id)
            ->withCount('feeds');
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(120)
                ->helperText('A label, e.g. "SAP S/4HANA — Sales".')
                ->columnSpanFull(),

            Select::make('provider')
                ->label('Source system')
                ->options(Profiles::labels())
                ->default('generic_rest')
                ->required()
                ->helperText('Sets sensible auth/pagination defaults; feeds can override.'),

            TextInput::make('base_url')
                ->label('Base URL')
                ->required()
                ->url()
                ->maxLength(1024)
                ->placeholder('https://host/api'),

            Select::make('auth_type')
                ->label('Authentication')
                ->options([
                    ApiConnection::AUTH_NONE      => 'None',
                    ApiConnection::AUTH_BEARER    => 'Bearer token',
                    ApiConnection::AUTH_BASIC     => 'Basic (user/password)',
                    ApiConnection::AUTH_API_KEY   => 'API key header',
                    ApiConnection::AUTH_OAUTH2_CC => 'OAuth2 client credentials',
                ])
                ->default(ApiConnection::AUTH_BEARER)
                ->live()
                ->required()
                ->columnSpanFull(),

            TextInput::make('auth_config.token')
                ->label('Bearer token')
                ->password()->revealable()->maxLength(4096)
                ->visible(fn ($get) => $get('auth_type') === ApiConnection::AUTH_BEARER)
                ->columnSpanFull(),

            TextInput::make('auth_config.username')
                ->label('Username')
                ->maxLength(255)
                ->visible(fn ($get) => $get('auth_type') === ApiConnection::AUTH_BASIC),
            TextInput::make('auth_config.password')
                ->label('Password')
                ->password()->revealable()->maxLength(1024)
                ->visible(fn ($get) => $get('auth_type') === ApiConnection::AUTH_BASIC),

            TextInput::make('auth_config.header_name')
                ->label('Header name')
                ->placeholder('X-Shopify-Access-Token')
                ->maxLength(255)
                ->visible(fn ($get) => $get('auth_type') === ApiConnection::AUTH_API_KEY),
            TextInput::make('auth_config.header_value')
                ->label('Header value / key')
                ->password()->revealable()->maxLength(4096)
                ->visible(fn ($get) => $get('auth_type') === ApiConnection::AUTH_API_KEY),

            TextInput::make('auth_config.token_url')
                ->label('Token URL')
                ->url()->maxLength(1024)
                ->visible(fn ($get) => $get('auth_type') === ApiConnection::AUTH_OAUTH2_CC)
                ->columnSpanFull(),
            TextInput::make('auth_config.client_id')
                ->label('Client ID')
                ->maxLength(512)
                ->visible(fn ($get) => $get('auth_type') === ApiConnection::AUTH_OAUTH2_CC),
            TextInput::make('auth_config.client_secret')
                ->label('Client secret')
                ->password()->revealable()->maxLength(2048)
                ->visible(fn ($get) => $get('auth_type') === ApiConnection::AUTH_OAUTH2_CC),
            TextInput::make('auth_config.scope')
                ->label('Scope')
                ->maxLength(512)
                ->visible(fn ($get) => $get('auth_type') === ApiConnection::AUTH_OAUTH2_CC)
                ->columnSpanFull(),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Only active connections are polled.')
                ->inline(false),

            Repeater::make('feeds')
                ->label('Feeds')
                ->relationship('feeds')
                ->schema([
                    Select::make('data_type')
                        ->label('Import type')
                        ->options(Import::dataTypeLabels() + [
                            \App\Models\ApiFeed::DATA_TYPE_DEMAND_FORECAST => 'Demand Forecast (F&R plan → baseline)',
                        ])
                        ->required()
                        ->searchable(),

                    TextInput::make('endpoint')
                        ->label('Endpoint')
                        ->required()
                        ->maxLength(1024)
                        ->helperText('Relative to base URL, e.g. /odata/API_SALES/A_Item or /admin/api/2024-01/orders.json'),

                    TextInput::make('records_path')
                        ->label('Records path')
                        ->maxLength(255)
                        ->helperText('Dot-path to the array in the response (value | d.results | orders). Blank = the response is the array.'),

                    Select::make('page_strategy')
                        ->label('Pagination')
                        ->options([
                            'none'          => 'None (single page)',
                            'page'          => 'Page number',
                            'offset'        => 'Offset / limit',
                            'odata_skiptop' => 'OData $skip/$top',
                            'next_link'     => 'OData @odata.nextLink',
                            'link_header'   => 'Link header (Shopify)',
                        ])
                        ->default('none'),

                    TextInput::make('page_size')
                        ->label('Page size')
                        ->numeric()->default(100)->minValue(1)->maxValue(1000),

                    KeyValue::make('field_map')
                        ->label('Field map (Autnyx header → API field)')
                        ->keyLabel('Autnyx column')
                        ->valueLabel('API field / path')
                        ->helperText('Optional. Blank = use the API field names as-is and let auto-mapping match them.')
                        ->columnSpanFull(),

                    KeyValue::make('params')
                        ->label('Extra query params')
                        ->columnSpanFull(),

                    Toggle::make('enabled')->label('Enabled')->default(true)->inline(false),
                ])
                ->columns(2)
                ->defaultItems(1)
                ->addActionLabel('Add feed')
                ->itemLabel(fn (array $state): ?string => ($state['endpoint'] ?? null)
                    ? (Import::dataTypeLabels()[$state['data_type'] ?? ''] ?? 'Feed') . ' — ' . $state['endpoint']
                    : 'New feed')
                ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                    $data['tenant_id'] = Filament::getTenant()?->id;

                    return $data;
                })
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('provider')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Profiles::labels()[$state] ?? $state),
                TextColumn::make('base_url')->limit(30)->tooltip(fn (ApiConnection $r) => $r->base_url)->toggleable(),
                TextColumn::make('feeds_count')->label('Feeds')->alignCenter()->badge()->color('gray'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ApiConnection $r) => $r->getStatusColor())
                    ->formatStateUsing(fn ($state) => match ($state) {
                        ApiConnection::STATUS_OK    => 'OK',
                        ApiConnection::STATUS_ERROR => 'Error',
                        default                     => 'Never polled',
                    }),
                IconColumn::make('is_active')->label('Active')->boolean()->alignCenter(),
                TextColumn::make('last_polled_at')->label('Last polled')->since()->placeholder('Never')->sortable(),
                TextColumn::make('last_error')->label('Last error')->placeholder('—')->limit(40)
                    ->tooltip(fn (ApiConnection $r) => $r->last_error)->color('danger')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('test')
                    ->label('Test')
                    ->icon('heroicon-o-signal')
                    ->color('info')
                    ->action(function (ApiConnection $record) {
                        $result = app(ConnectorRegistry::class)->for($record)->test($record);
                        Notification::make()
                            ->title($result['ok'] ? 'Connection OK' : 'Connection failed')
                            ->body($result['message'])
                            ->color($result['ok'] ? 'success' : 'danger')
                            ->persistent(! $result['ok'])
                            ->send();
                    }),

                Action::make('poll')
                    ->label('Poll now')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Pull data now from this connection\'s enabled feeds and run it through the import pipeline.')
                    ->visible(fn (ApiConnection $record) => $record->is_active)
                    ->action(function (ApiConnection $record) {
                        try {
                            $n = app(ApiPollService::class)->pollConnection($record->fresh('feeds'));
                            Notification::make()
                                ->title($n > 0 ? "Ingested {$n} feed(s)" : 'No new data')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Poll failed')->body($e->getMessage())->danger()->persistent()->send();
                        }
                    }),

                EditAction::make(),
                DeleteAction::make()->requiresConfirmation(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No API connections yet')
            ->emptyStateDescription('Connect a source system (SAP, Dynamics, Shopify, …) to pull data via API instead of flat files.')
            ->emptyStateIcon('heroicon-o-bolt');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListApiConnections::route('/'),
            'create' => Pages\CreateApiConnection::route('/create'),
            'edit'   => Pages\EditApiConnection::route('/{record}/edit'),
        ];
    }
}
