<?php

namespace App\Filament\Widgets\Assortment;

use App\Filament\Support\AssortmentDecisionActions;
use App\Models\AssortmentProgramme;
use App\Services\Assortment\TailProgrammes;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\On;

/** v2.3 — the clean-up programmes and how far each has got, store by store. */
class CleanUpProgrammes extends TableWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    #[On('programmes-changed')]
    public function refreshProgrammes(): void {}

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();
        $svc = app(TailProgrammes::class);
        $currency = $tenant?->currencyCode();

        return $table
            ->heading('Programmes')
            ->query(AssortmentProgramme::query()->where('tenant_id', $tenant?->id)->latest('id'))
            ->columns([
                TextColumn::make('name')->label('Programme')->weight('semibold')->wrap()
                    ->description(fn (AssortmentProgramme $p) => count($p->skus) . ' product(s) · ' . $p->stores . ' store(s)'),
                TextColumn::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state) => AssortmentProgramme::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) { AssortmentProgramme::ACCEPTED => 'info', AssortmentProgramme::DONE => 'success', AssortmentProgramme::CANCELLED => 'gray', default => 'warning' }),
                TextColumn::make('value')->label('Worth a year')->alignEnd()
                    ->formatStateUsing(fn ($state, AssortmentProgramme $p) => Money::compact((float) ($p->value['low'] ?? 0), $currency) . '–' . Money::compact((float) ($p->value['high'] ?? 0), $currency))
                    ->description(fn (AssortmentProgramme $p) => $p->metres ? number_format($p->metres, 2) . ' m of shelf freed' : null),
                TextColumn::make('progress')->label('Store by store')->wrap()
                    ->state(function (AssortmentProgramme $p) use ($svc, $currency) {
                        if ($p->gap_ids === null) {
                            return $p->status === AssortmentProgramme::CANCELLED ? '—' : 'Not accepted yet';
                        }
                        $g = $svc->progress($p);

                        return $g['done'] . ' of ' . $g['total'] . ' done' . ($g['cancelled'] ? ' · ' . $g['cancelled'] . ' cancelled' : '')
                            . ($g['measured'] ? ' · ' . $g['measured'] . ' measured (' . $g['worked'] . ' worked, ' . ($g['uplift'] >= 0 ? '+' : '−') . Money::compact(abs($g['uplift']), $currency) . ' a year)' : '');
                    }),
                TextColumn::make('due_at')->label('Due')->date('j M Y')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('accept')->label('Accept')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (AssortmentProgramme $p) => $p->status === AssortmentProgramme::PROPOSED)
                    ->modalDescription('Accepts the open delist of each product in every store that has one. Make the changes in your own merchandising system; each store marks its change done.')
                    ->form([
                        Select::make('assignee_id')->label('Who makes the changes')->options(fn () => AssortmentDecisionActions::people())->searchable()->placeholder('Each store\'s manager, if one is set'),
                        DatePicker::make('due_at')->label('Due')->default(now()->addDays(21))->minDate(today()),
                    ])
                    ->action(function (AssortmentProgramme $p, array $data) use ($svc) {
                        $n = $svc->accept($p, auth()->user(), $data['assignee_id'] ?? null, $data['due_at'] ?? null);
                        Notification::make()->title("Programme accepted: {$n} delist(s) to make")->success()->send();
                    }),
                Action::make('cancel')->label('Cancel')->color('gray')->requiresConfirmation()
                    ->visible(fn (AssortmentProgramme $p) => $p->status === AssortmentProgramme::PROPOSED)
                    ->action(fn (AssortmentProgramme $p) => $svc->cancel($p, auth()->user())),
            ])
            ->paginated([10, 25])
            ->emptyStateHeading('No programmes yet')
            ->emptyStateDescription('Select candidates above and make a programme.');
    }
}
