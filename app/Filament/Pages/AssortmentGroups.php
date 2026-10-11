<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Models\AssortmentGrouping;
use App\Models\Tenant;
use App\Services\Assortment\StoreGroupings;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * v2.4 — demand-based store groups (admins): the groups in force, a proposal
 * worked out from what each store sells, compared store by store with today's
 * peers (a native Filament table), and approve, reject or retire. Approved
 * groups are the Assortment peer groups from the next run; the version is on
 * every decision made under them.
 */
class AssortmentGroups extends Page implements HasTable
{
    use GatesAssortmentScreen;
    use InteractsWithTable;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const ADMIN_ONLY = true;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Store Groups';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'assortment-groups';

    protected string $view = 'filament.assortment.groups';

    public function getTitle(): string
    {
        return 'Store groups';
    }

    private function tid(): int
    {
        return (int) Filament::getTenant()?->id;
    }

    public function proposal(): ?AssortmentGrouping
    {
        return AssortmentGrouping::where('tenant_id', $this->tid())->where('status', AssortmentGrouping::PROPOSED)->latest('version')->first();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(fn () => $this->proposal() ? 'Store by store: today\'s peers against the proposal' : 'Store by store')
            ->records(fn () => collect($this->proposal()?->comparison['stores'] ?? [])
                ->sortBy(fn ($r) => [$r['moves'] ? 0 : 1, $r['name']])->all())
            ->columns([
                TextColumn::make('name')->label('Store')->weight('semibold')->description(fn (array $record) => $record['format']),
                TextColumn::make('now')->label('Judged against today')->wrap(),
                TextColumn::make('proposed')->label('Proposed group')->wrap(),
                TextColumn::make('same_peers')->label('Same peers')->alignEnd()->formatStateUsing(fn ($state) => (int) round((float) $state * 100) . '%'),
                IconColumn::make('moves')->label('Peers change')->boolean()->trueIcon('heroicon-o-arrows-right-left')->falseIcon('heroicon-o-minus')
                    ->trueColor('warning')->falseColor('gray'),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No proposal')
            ->emptyStateDescription('Work out a proposal to see, store by store, how its peers would change.');
    }

    protected function getHeaderActions(): array
    {
        $svc = app(StoreGroupings::class);

        return [
            Action::make('propose')->label('Work out a proposal')->icon('heroicon-o-sparkles')
                ->modalDescription('Groups the stores by what they sell over the last 90 days — each store\'s mix of categories and its price level — kept stable against the groups in force. Nothing changes until it is approved.')
                ->requiresConfirmation()
                ->action(function () use ($svc) {
                    $g = $svc->propose(Filament::getTenant(), auth()->user());
                    Notification::make()->title('Proposal ' . $g->label() . ': ' . count($g->groups) . ' groups, ' . $g->comparison['moved'] . ' store(s) with new peers')->success()->send();
                }),
            Action::make('approve')->label('Approve')->icon('heroicon-o-check')->color('success')
                ->visible(fn () => $this->proposal() !== null)
                ->modalDescription('These groups become the Assortment peer groups from the next run: benchmarks, decisions and plans are judged within them, and each carries this version. Root Cause and the platform clusters are not changed. Retiring them later is one action.')
                ->form([Textarea::make('note')->rows(2)->maxLength(500)])
                ->action(function (array $data) use ($svc) {
                    $svc->approve($this->proposal(), auth()->user(), $data['note'] ?? null);
                    Notification::make()->title('Approved — used from the next run')->success()->send();
                }),
            Action::make('reject')->label('Reject')->color('gray')
                ->visible(fn () => $this->proposal() !== null)
                ->form([Textarea::make('note')->label('Why')->rows(2)->maxLength(500)])
                ->action(function (array $data) use ($svc) {
                    $svc->reject($this->proposal(), auth()->user(), $data['note'] ?? null);
                    Notification::make()->title('Rejected — nothing changes')->success()->send();
                }),
            Action::make('retire')->label('Retire the groups in force')->color('danger')->requiresConfirmation()
                ->visible(fn () => StoreGroupings::active($this->tid()) !== null)
                ->modalDescription('From the next run, stores are compared within the platform clusters or their format again.')
                ->action(function () use ($svc) {
                    $svc->retire(Filament::getTenant(), auth()->user());
                    Notification::make()->title('Retired — back to clusters and formats from the next run')->success()->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        $active = StoreGroupings::active($this->tid());
        $proposal = $this->proposal();
        $shown = $proposal ?? $active;

        return [
            'active'   => $active,
            'proposal' => $proposal,
            // v2.5 — the groups drawn: the proposal when one is waiting, else the groups in force.
            'shown'    => $shown,
            'viz'      => $shown ? app(\App\Services\Assortment\GroupVisuals::class)->build($this->tid(), $shown) : null,
            'history'  => AssortmentGrouping::with('decider:id,name')->where('tenant_id', $this->tid())->orderByDesc('version')->limit(12)->get(),
        ];
    }
}
