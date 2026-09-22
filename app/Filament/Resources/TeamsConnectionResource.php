<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TeamsConnectionResource\Pages;
use App\Models\TeamsConnection;
use App\Services\Teams\TeamsNotifier;
use App\Services\Teams\TeamsUserResolver;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * TeamsConnectionResource — Microsoft Teams notification target for a tenant.
 *
 * Channel Adaptive Cards (via a Workflows/Incoming-Webhook, or Graph) plus
 * per-user activity-feed pings (via Graph). The Autnyx Entra app is global
 * config; this only holds the customer's identifiers. Admin-gated — configuring
 * an external notification target is consequential.
 *
 * See claude/teams-notifications.md.
 */
class TeamsConnectionResource extends Resource
{
    protected static ?string $model = TeamsConnection::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'Teams Notifications';

    protected static ?int $navigationSort = 3;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ($user->is_super_admin || $user->is_tenant_admin));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', Filament::getTenant()?->id);
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Name')
                ->maxLength(120)
                ->helperText('A label for this connection, e.g. "Midan Ops Teams".')
                ->columnSpanFull(),

            TextInput::make('aad_tenant_id')
                ->label('Microsoft 365 tenant ID')
                ->required()
                ->maxLength(64)
                ->placeholder('00000000-0000-0000-0000-000000000000')
                ->helperText('The customer\'s Azure AD (Entra) tenant GUID — Entra admin center → Overview.')
                ->columnSpanFull(),

            Toggle::make('post_to_channel')
                ->label('Post to a Teams channel')
                ->default(true)
                ->live()
                ->inline(false),

            TextInput::make('channel_webhook_url')
                ->label('Channel webhook URL')
                ->password()
                ->revealable()
                ->maxLength(2048)
                ->dehydrated(fn ($state) => filled($state))
                ->helperText('Recommended: a Teams Workflows / Incoming-Webhook URL for the target channel. Leave blank to keep the existing value, or to post via Graph instead.')
                ->visible(fn ($get) => (bool) $get('post_to_channel'))
                ->columnSpanFull(),

            TextInput::make('team_id')
                ->label('Team ID')
                ->maxLength(128)
                ->helperText('Only needed to post via Graph (no webhook). The Teams team GUID.')
                ->visible(fn ($get) => (bool) $get('post_to_channel')),

            TextInput::make('channel_id')
                ->label('Channel ID')
                ->maxLength(128)
                ->helperText('Only needed to post via Graph (no webhook). The channel GUID.')
                ->visible(fn ($get) => (bool) $get('post_to_channel')),

            Toggle::make('notify_users')
                ->label('Send per-user activity-feed pings')
                ->default(true)
                ->live()
                ->inline(false),

            TextInput::make('teams_app_id')
                ->label('Autnyx Teams app ID')
                ->maxLength(128)
                ->helperText('The installed Autnyx Teams app id — required for per-user activity-feed notifications.')
                ->visible(fn ($get) => (bool) $get('notify_users'))
                ->columnSpanFull(),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('Only active connections deliver notifications.')
                ->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                TextColumn::make('aad_tenant_id')
                    ->label('Tenant ID')
                    ->limit(18)
                    ->tooltip(fn (TeamsConnection $record) => $record->aad_tenant_id)
                    ->searchable(),

                IconColumn::make('post_to_channel')
                    ->label('Channel')
                    ->boolean()
                    ->alignCenter(),

                IconColumn::make('notify_users')
                    ->label('Per-user')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (TeamsConnection $record) => $record->getStatusColor())
                    ->formatStateUsing(fn ($state) => match ($state) {
                        TeamsConnection::STATUS_OK    => 'OK',
                        TeamsConnection::STATUS_ERROR => 'Error',
                        default                       => 'Never sent',
                    }),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('last_success_at')
                    ->label('Last sent')
                    ->dateTime('M j, Y H:i')
                    ->placeholder('Never')
                    ->since()
                    ->sortable(),

                TextColumn::make('last_error')
                    ->label('Last error')
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(fn (TeamsConnection $record) => $record->last_error)
                    ->color('danger')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        TeamsConnection::STATUS_OK    => 'OK',
                        TeamsConnection::STATUS_ERROR => 'Error',
                    ]),
                TernaryFilter::make('post_to_channel')->label('Channel'),
                TernaryFilter::make('notify_users')->label('Per-user'),
                TernaryFilter::make('is_active')->label('Active'),
                Filter::make('last_success_at')
                    ->label('Last sent')
                    ->form([
                        DatePicker::make('from')->label('From'),
                        DatePicker::make('until')->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'],  fn (Builder $q, $d) => $q->whereDate('last_success_at', '>=', $d))
                            ->when($data['until'], fn (Builder $q, $d) => $q->whereDate('last_success_at', '<=', $d));
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('test')
                    ->label('Send test')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->action(function (TeamsConnection $record) {
                        try {
                            app(TeamsNotifier::class)->sendTest($record->fresh(), auth()->user());
                            Notification::make()
                                ->title('Test notification sent')
                                ->body('Check the Teams channel and/or your activity feed.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Test failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                Action::make('resyncUsers')
                    ->label('Resync users')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Look up each user\'s Microsoft Teams id from their email via Graph, so per-user pings can reach them. Requires the User.Read.All permission to be consented.')
                    ->action(function (TeamsConnection $record) {
                        try {
                            $r = app(TeamsUserResolver::class)->resyncTenant($record->tenant_id);
                            Notification::make()
                                ->title("Mapped {$r['updated']} user(s)")
                                ->body("{$r['skipped']} skipped, {$r['failed']} failed.")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Resync failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
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
            ->emptyStateHeading('No Teams connection yet')
            ->emptyStateDescription('Add a connection to deliver Autnyx alerts to Microsoft Teams instead of (or alongside) email.')
            ->emptyStateIcon('heroicon-o-chat-bubble-left-right');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTeamsConnections::route('/'),
            'create' => Pages\CreateTeamsConnection::route('/create'),
            'edit'   => Pages\EditTeamsConnection::route('/{record}/edit'),
        ];
    }
}
