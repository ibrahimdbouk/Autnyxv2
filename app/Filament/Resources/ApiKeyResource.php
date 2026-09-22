<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApiKeyResource\Pages;
use App\Models\ApiKey;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * ApiKeyResource — manage public-API keys (scoped, revocable, tenant-bound). The
 * plaintext token is shown once at creation; only its hash is stored. Admin-gated.
 * See claude/public-api.md.
 */
class ApiKeyResource extends Resource
{
    protected static ?string $model = ApiKey::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-key';

    protected static \UnitEnum|string|null $navigationGroup = 'Data';

    protected static ?string $navigationLabel = 'API Keys';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user && ($user->is_super_admin || $user->is_tenant_admin));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id);
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            TextInput::make('name')
                ->required()
                ->maxLength(120)
                ->helperText('A label so you can recognise this key later.')
                ->columnSpanFull(),

            CheckboxList::make('scopes')
                ->label('Scopes')
                ->options(ApiKey::scopeOptions())
                ->required()
                ->columns(2)
                ->helperText('What this key is allowed to do.')
                ->columnSpanFull(),

            DateTimePicker::make('expires_at')
                ->label('Expires at')
                ->helperText('Optional. Leave blank for a key that never expires.')
                ->seconds(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('semibold'),
                TextColumn::make('prefix')->label('Key')->formatStateUsing(fn ($state) => $state . '…')->copyable(),
                TextColumn::make('scopes')->badge()->separator(',')->limitList(3),
                TextColumn::make('status')
                    ->state(fn (ApiKey $r) => $r->revoked_at ? 'revoked' : ($r->isActive() ? 'active' : 'expired'))
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'active'  => 'success',
                        'revoked' => 'danger',
                        default   => 'gray',
                    }),
                TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('Never')->sortable(),
                TextColumn::make('expires_at')->label('Expires')->dateTime('M j, Y')->placeholder('Never')->toggleable(),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('revoked')
                    ->label('Revoked')
                    ->placeholder('All')
                    ->trueLabel('Revoked')
                    ->falseLabel('Not revoked')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('revoked_at'),
                        false: fn (Builder $query) => $query->whereNull('revoked_at'),
                        blank: fn (Builder $query) => $query,
                    ),
                TernaryFilter::make('used')
                    ->label('Used')
                    ->placeholder('All')
                    ->trueLabel('Used at least once')
                    ->falseLabel('Never used')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('last_used_at'),
                        false: fn (Builder $query) => $query->whereNull('last_used_at'),
                        blank: fn (Builder $query) => $query,
                    ),
                Filter::make('created_at')
                    ->label('Created')
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
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Revoking immediately disables this key. This cannot be undone.')
                    ->visible(fn (ApiKey $record) => $record->revoked_at === null)
                    ->action(function (ApiKey $record) {
                        $record->forceFill(['revoked_at' => now()])->save();
                        Notification::make()->title('Key revoked')->success()->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No API keys yet')
            ->emptyStateDescription('Create a key to let external tools read Autnyx data or push data in via the public API.')
            ->emptyStateIcon('heroicon-o-key');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListApiKeys::route('/'),
            'create' => Pages\CreateApiKey::route('/create'),
        ];
    }
}
