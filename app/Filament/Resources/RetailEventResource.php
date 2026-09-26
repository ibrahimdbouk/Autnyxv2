<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RetailEventResource\Pages;
use App\Models\RetailEvent;
use App\Services\Calendar\RetailCalendarDefaults;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * W13 — the retail calendar: Ramadan, Eid, back to school, summer, Christmas,
 * White Friday, national days (defaults, editable) and the tenant's own
 * events. Detection leaves alone a demand swing an event explains — when the
 * item's department moved the same way — the same way it does for promotions.
 */
class RetailEventResource extends Resource
{
    protected static ?string $model = RetailEvent::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Retail Calendar';

    protected static ?string $modelLabel = 'event';

    protected static ?int $navigationSort = 5;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) ($u && ($u->is_super_admin || $u->is_tenant_admin));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id);
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            Section::make('Event')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(120),
                Select::make('kind')->options(RetailEvent::KINDS)->default('custom')->required(),
                DatePicker::make('starts_on')->label('Starts')->required(),
                DatePicker::make('ends_on')->label('Ends')->required()->afterOrEqual('starts_on'),
                TextInput::make('lead_days')->label('Build-up (days before)')->numeric()->minValue(0)->maxValue(60)->default(0)
                    ->helperText('Shoppers stock up before — e.g. 7 for Ramadan.'),
                TextInput::make('tail_days')->label('After-effect (days after)')->numeric()->minValue(0)->maxValue(60)->default(0)
                    ->helperText('The dip after — e.g. 7 after Eid al-Fitr.'),
                TagsInput::make('categories')->label('Only these departments / categories')->placeholder('Leave empty for every product')
                    ->helperText('As they appear in your product file (department, category or subcategory).'),
                TagsInput::make('countries')->label('Only stores in these countries')->placeholder('e.g. AE, SA — empty for everywhere'),
                Toggle::make('active')->default(true)->inline(false),
                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('semibold')
                    ->description(fn (RetailEvent $e) => RetailEvent::KINDS[$e->kind] ?? $e->kind),
                TextColumn::make('starts_on')->label('Dates')->date('j M Y')->sortable()
                    ->description(fn (RetailEvent $e) => 'to ' . $e->ends_on?->format('j M Y')
                        . ($e->lead_days || $e->tail_days ? " · −{$e->lead_days}/+{$e->tail_days} days" : '')),
                TextColumn::make('categories')->label('Departments')->badge()->placeholder('All'),
                TextColumn::make('countries')->badge()->placeholder('All'),
                TextColumn::make('promos')->label('Promotions running')
                    ->state(fn (RetailEvent $e) => DB::table('promotions')->where('tenant_id', $e->tenant_id)
                        ->where('starts_on', '<=', $e->ends_on)->where('ends_on', '>=', $e->starts_on)->distinct()->count('promotion_ref') ?: null)
                    ->placeholder('—'),
                IconColumn::make('active')->boolean(),
                TextColumn::make('source')->badge()->color(fn ($state) => $state === 'default' ? 'gray' : 'primary')
                    ->formatStateUsing(fn ($state) => $state === 'default' ? 'Default' : 'Yours'),
            ])
            ->defaultSort('starts_on')
            ->filters([
                SelectFilter::make('year')->options(fn () => RetailEvent::where('tenant_id', Filament::getTenant()?->id)
                    ->distinct()->orderBy('year')->pluck('year', 'year')->all())
                    ->default((string) now()->year),
                SelectFilter::make('kind')->options(RetailEvent::KINDS),
                TernaryFilter::make('active'),
            ])
            ->actions([EditAction::make(), DeleteAction::make()->visible(fn (RetailEvent $e) => $e->source === 'tenant')])
            ->headerActions([
                Action::make('defaults')->label('Add default events for next year')->icon('heroicon-o-sparkles')->color('gray')
                    ->action(function () {
                        $y = (int) now()->year + 2;
                        $n = app(RetailCalendarDefaults::class)->ensure((int) Filament::getTenant()?->id, (int) now()->year - 2, $y);
                        Notification::make()->title($n ? "{$n} event(s) added up to {$y}" : 'The calendar already has them')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No events yet');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRetailEvents::route('/'),
            'create' => Pages\CreateRetailEvent::route('/create'),
            'edit'   => Pages\EditRetailEvent::route('/{record}/edit'),
        ];
    }

    /** Form data → row: key and year for a new event, tidy lists. */
    public static function prepare(array $data, ?RetailEvent $record = null): array
    {
        $data['categories'] = array_values(array_filter(array_map('trim', (array) ($data['categories'] ?? [])))) ?: null;
        $data['countries'] = array_values(array_filter(array_map(fn ($c) => \App\Services\Calendar\RetailCalendar::countryCode($c), (array) ($data['countries'] ?? [])))) ?: null;
        if ($record === null) {
            $data['year'] = (int) \Illuminate\Support\Carbon::parse($data['starts_on'])->year;
            $base = 'own_' . Str::limit(Str::slug($data['name'] ?? 'event', '_'), 40, '');
            $key = $base;
            for ($i = 2; RetailEvent::where('tenant_id', $data['tenant_id'])->where('key', $key)->where('year', $data['year'])->exists(); $i++) {
                $key = "{$base}_{$i}";
            }
            $data['key'] = $key;
            $data['source'] = 'tenant';
        }

        return $data;
    }
}
