<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SftpConnectionResource\Pages;
use App\Models\Import;
use App\Models\SftpConnection;
use App\Services\Sftp\SftpPollService;
use App\Services\Sftp\SftpService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
 * SftpConnectionResource — M14. Configure automated SFTP flat-file pulls.
 *
 * A connection points at a customer/vendor SFTP endpoint; each of its feeds maps
 * a remote directory + filename pattern to an import type. A scheduled poller
 * (sftp:poll, hourly) downloads new matching files and runs them through the
 * standard import pipeline. Credentials are encrypted at rest.
 *
 * Admin-gated — configuring an SFTP endpoint is consequential.
 */
class SftpConnectionResource extends Resource
{
    protected static ?string $model = SftpConnection::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-server-stack';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'SFTP Feeds';

    protected static ?int $navigationSort = 2;

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
                ->label('Name')
                ->required()
                ->maxLength(120)
                ->helperText('A label for this endpoint, e.g. "Acme Vendor SFTP".')
                ->columnSpanFull(),

            TextInput::make('host')
                ->label('Host')
                ->required()
                ->maxLength(255)
                ->placeholder('sftp.example.com'),

            TextInput::make('port')
                ->label('Port')
                ->numeric()
                ->default(22)
                ->minValue(1)
                ->maxValue(65535)
                ->required(),

            TextInput::make('username')
                ->label('Username')
                ->required()
                ->maxLength(255),

            Select::make('auth_type')
                ->label('Authentication')
                ->options([
                    SftpConnection::AUTH_PASSWORD => 'Password',
                    SftpConnection::AUTH_KEY      => 'Private key',
                ])
                ->default(SftpConnection::AUTH_PASSWORD)
                ->live()
                ->required(),

            TextInput::make('password')
                ->label('Password')
                ->password()
                ->revealable()
                ->maxLength(1024)
                ->autocomplete('new-password')
                ->dehydrated(fn ($state) => filled($state))
                ->helperText('Leave blank to keep the existing password.')
                ->visible(fn ($get) => $get('auth_type') === SftpConnection::AUTH_PASSWORD)
                ->columnSpanFull(),

            Textarea::make('private_key')
                ->label('Private key (PEM)')
                ->rows(5)
                ->dehydrated(fn ($state) => filled($state))
                ->helperText('Paste the OpenSSH/PEM private key. Leave blank to keep the existing key.')
                ->visible(fn ($get) => $get('auth_type') === SftpConnection::AUTH_KEY)
                ->columnSpanFull(),

            TextInput::make('private_key_passphrase')
                ->label('Key passphrase')
                ->password()
                ->revealable()
                ->maxLength(1024)
                ->dehydrated(fn ($state) => filled($state))
                ->helperText('Optional — only if the private key is passphrase-protected.')
                ->visible(fn ($get) => $get('auth_type') === SftpConnection::AUTH_KEY)
                ->columnSpanFull(),

            TextInput::make('base_path')
                ->label('Base path')
                ->maxLength(1024)
                ->placeholder('/')
                ->helperText('Root directory on the server. Feed paths are relative to this. Leave blank for the login directory.')
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
                        ->options(Import::dataTypeLabels())
                        ->required()
                        ->searchable(),

                    TextInput::make('remote_path')
                        ->label('Remote folder')
                        ->default('.')
                        ->maxLength(1024)
                        ->helperText('Relative to the base path. "." = base path itself.'),

                    TextInput::make('filename_pattern')
                        ->label('Filename pattern')
                        ->default('*.csv')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Glob, case-insensitive. e.g. sales_*.csv'),

                    TextInput::make('archive_path')
                        ->label('Archive folder')
                        ->maxLength(1024)
                        ->helperText('Optional — move each file here after import (relative to base path).'),

                    Toggle::make('delete_after')
                        ->label('Delete after import')
                        ->helperText('Ignored if an archive folder is set.')
                        ->inline(false),

                    Toggle::make('enabled')
                        ->label('Enabled')
                        ->default(true)
                        ->inline(false),
                ])
                ->columns(2)
                ->defaultItems(1)
                ->addActionLabel('Add feed')
                ->itemLabel(fn (array $state): ?string => ($state['filename_pattern'] ?? null)
                    ? (Import::dataTypeLabels()[$state['data_type'] ?? ''] ?? $state['data_type'] ?? 'Feed') . ' — ' . $state['filename_pattern']
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
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                TextColumn::make('host')
                    ->label('Host')
                    ->description(fn (SftpConnection $record) => $record->username . '@' . $record->host . ':' . ($record->port ?: 22))
                    ->searchable(),

                TextColumn::make('feeds_count')
                    ->label('Feeds')
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (SftpConnection $record) => $record->getStatusColor())
                    ->formatStateUsing(fn ($state) => match ($state) {
                        SftpConnection::STATUS_OK    => 'OK',
                        SftpConnection::STATUS_ERROR => 'Error',
                        default                      => 'Never polled',
                    }),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('last_polled_at')
                    ->label('Last polled')
                    ->dateTime('M j, Y H:i')
                    ->placeholder('Never')
                    ->since()
                    ->sortable(),

                TextColumn::make('last_error')
                    ->label('Last error')
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(fn (SftpConnection $record) => $record->last_error)
                    ->color('danger')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('test')
                    ->label('Test')
                    ->icon('heroicon-o-signal')
                    ->color('info')
                    ->action(function (SftpConnection $record) {
                        $result = app(SftpService::class)->testConnection($record);
                        if ($result['ok']) {
                            Notification::make()
                                ->title('Connection successful')
                                ->body($result['message'])
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Connection failed')
                                ->body($result['message'])
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),

                Action::make('poll')
                    ->label('Poll now')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Connect now and import any new matching files across this connection\'s enabled feeds.')
                    ->visible(fn (SftpConnection $record) => $record->is_active)
                    ->action(function (SftpConnection $record) {
                        try {
                            $count = app(SftpPollService::class)->pollConnection($record->fresh());
                            Notification::make()
                                ->title($count > 0 ? "Imported {$count} file(s)" : 'No new files')
                                ->body($count > 0
                                    ? 'New flat files were pulled and queued through the import pipeline.'
                                    : 'The connection was reached, but no new matching files were found.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Poll failed')
                                ->body(app(SftpService::class)->cleanError($e->getMessage()))
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
            ->emptyStateHeading('No SFTP feeds yet')
            ->emptyStateDescription('Add a connection to automatically pull flat files from a customer or vendor SFTP server.')
            ->emptyStateIcon('heroicon-o-server-stack');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListSftpConnections::route('/'),
            'create' => Pages\CreateSftpConnection::route('/create'),
            'edit'   => Pages\EditSftpConnection::route('/{record}/edit'),
        ];
    }
}
