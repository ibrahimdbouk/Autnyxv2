<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TeamResource\Pages;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Teams';

    protected static ?int $navigationSort = 10;

    public static function getEloquentQuery(): Builder
    {
        $tenantId = Filament::getTenant()?->id;
        return parent::getEloquentQuery()
            ->where('tenant_id', $tenantId)
            ->withCount('members');
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Team Name')
                ->required()
                ->maxLength(100)
                ->columnSpanFull(),

            TextInput::make('description')
                ->label('Description')
                ->maxLength(255)
                ->columnSpanFull(),

            TextInput::make('email')
                ->label('Team Email')
                ->email()
                ->maxLength(150)
                ->helperText('Used for escalation notifications'),

            Toggle::make('is_default')
                ->label('Default Team')
                ->helperText('New investigations are auto-assigned to the default team')
                ->inline(false),

            Repeater::make('teamMemberRecords')
                ->label('Members')
                ->relationship('teamMemberRecords')
                ->schema([
                    Select::make('user_id')
                        ->label('User')
                        ->options(fn () => User::where(
                            'current_team_id',
                            Filament::getTenant()?->id
                        )->pluck('name', 'id')->toArray())
                        ->searchable()
                        ->required(),

                    Select::make('role')
                        ->label('Role')
                        ->options([
                            'member' => 'Member',
                            'lead'   => 'Lead',
                        ])
                        ->default('member')
                        ->required(),
                ])
                ->columns(2)
                ->defaultItems(0)
                ->addActionLabel('Add Member')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                TextColumn::make('description')
                    ->limit(50)
                    ->placeholder('—'),

                TextColumn::make('members_count')
                    ->label('Members')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—')
                    ->copyable(),

                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_default')
                    ->label('Default Team'),

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
            ->defaultSort('name')
            ->actions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelationManagers(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListTeams::route('/'),
            'create' => Pages\CreateTeam::route('/create'),
            'edit'   => Pages\EditTeam::route('/{record}/edit'),
        ];
    }
}
