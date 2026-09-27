<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesAssortmentScreen;
use App\Models\AssortmentPlan;
use App\Models\AssortmentScenario;
use App\Models\Tenant;
use App\Services\Assortment\CategoryStrategy;
use App\Services\Assortment\StudioService;
use App\Services\Assortment\TenantAssortment;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * v1.5 Phase 3 — the Assortment Decision Studio. One workspace for one store ×
 * category: the range today → its problems and opportunities → the changes
 * being tried → what they would do (as ranges, labelled Simulated) → scenarios
 * side by side → make one the shelf's plan, which is then accepted as one reset
 * task and measured like any other plan.
 *
 * Every click recalculates in the page: the shelf's evidence is prepared once
 * (StudioService::context, cached) and only the person's inputs travel.
 */
class AssortmentStudio extends Page
{
    use GatesAssortmentScreen;

    const APP_KEY = Tenant::APP_ASSORTMENT;

    const SCREEN_KEY = 'assortment_studio';

    public const ROWS = 40;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Decision Studio';

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'assortment-studio';

    protected string $view = 'filament.pages.assortment-studio';

    #[Url(as: 'store')]
    public ?int $store = null;

    #[Url(as: 'category')]
    public ?string $category = null;

    #[Url(as: 'view')]
    public string $tab = 'workspace';

    // The person's inputs — all that travels with each click.
    public ?string $objective = null;

    public ?string $room = null;

    public ?int $maxSize = null;

    public ?int $minSize = null;

    /** @var array<int,array{kind:string, sku:string}> */
    public array $picks = [];

    /** @var array<int,string> */
    public array $protect = [];

    /** @var array<int,string> */
    public array $unprotect = [];

    /** @var array<int,string> stock fixes left out */
    public array $skip = [];

    public ?string $focus = null;

    public string $search = '';

    public bool $showAll = false;

    public ?string $loaded = null;

    private ?array $ctxMemo = null;

    private ?array $resultMemo = null;

    public function getTitle(): string
    {
        return 'Decision Studio';
    }

    public static function opensFor(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Tenant && (TenantAssortment::isLive($tenant) || auth()->user()?->canManageUsers());
    }

    public function mount(): void
    {
        if (! static::opensFor()) {
            return;
        }
        $svc = app(StudioService::class);
        $stores = $svc->stores(Filament::getTenant(), auth()->user());
        if ($this->store === null) {
            $top = AssortmentPlans::scoped()->whereIn('status', [AssortmentPlan::STATUS_PROPOSED, AssortmentPlan::STATUS_DRAFT])
                ->where('feasible', true)->orderByRaw('value_mid * confidence DESC')->orderBy('id')->first(['store_id', 'category']);
            $this->store = $top?->store_id ?? (array_key_first($stores) ?: null);
            $this->category ??= $top?->category;
        }
        abort_if($this->store !== null && ! isset($stores[$this->store]), 404);
        if ($this->store !== null && ($this->category === null || ! $this->hasCategory($this->category))) {
            $this->category = $svc->categories(Filament::getTenant(), $this->store)[0]['category'] ?? null;
        }
    }

    private function hasCategory(string $category): bool
    {
        return collect(app(StudioService::class)->categories(Filament::getTenant(), (int) $this->store))->contains('category', $category);
    }

    // ── Switching shelf ───────────────────────────────────────────────────────

    public function updatedStore(): void
    {
        $stores = app(StudioService::class)->stores(Filament::getTenant(), auth()->user());
        abort_unless(isset($stores[(int) $this->store]), 404);
        if ($this->category === null || ! $this->hasCategory($this->category)) {
            $this->category = app(StudioService::class)->categories(Filament::getTenant(), (int) $this->store)[0]['category'] ?? null;
        }
        $this->resetWorkspace();
    }

    public function updatedCategory(): void
    {
        abort_unless($this->category !== null && $this->hasCategory($this->category), 404);
        $this->resetWorkspace();
    }

    public function updated(string $name): void
    {
        $this->resultMemo = null;
        if (in_array($name, ['objective', 'room', 'maxSize', 'minSize'], true)) {
            $this->loaded = null;
        }
    }

    public function resetWorkspace(): void
    {
        $this->objective = $this->room = null;
        $this->maxSize = $this->minSize = null;
        $this->picks = $this->protect = $this->unprotect = $this->skip = [];
        $this->focus = $this->loaded = null;
        $this->ctxMemo = $this->resultMemo = null;
    }

    // ── Changing the range ────────────────────────────────────────────────────

    public function add(string $sku): void
    {
        $this->pick(AssortmentPlan::ADD, $sku);
    }

    public function remove(string $sku): void
    {
        $this->pick(AssortmentPlan::DELIST, $sku);
    }

    /** A pick; the opposite pick of the same product undoes it instead. */
    private function pick(string $kind, string $sku): void
    {
        foreach ($this->picks as $i => $p) {
            if ($p['sku'] === $sku) {
                if ($p['kind'] !== $kind) {
                    $this->undo($i);
                }

                return;
            }
        }
        $this->picks[] = ['kind' => $kind, 'sku' => $sku];
        $this->focus = $sku;
        $this->changed();
    }

    public function undo(int $index): void
    {
        unset($this->picks[$index]);
        $this->picks = array_values($this->picks);
        $this->changed();
    }

    public function toggleProtect(string $sku): void
    {
        $default = ! empty($this->ctx()['shelf']['skus'][$sku]['protected'] ?? null);
        $list = $default ? 'unprotect' : 'protect';
        $this->{$list} = in_array($sku, $this->{$list}, true) ? array_values(array_diff($this->{$list}, [$sku])) : [...$this->{$list}, $sku];
        $this->changed();
    }

    public function toggleSkip(string $sku): void
    {
        $this->skip = in_array($sku, $this->skip, true) ? array_values(array_diff($this->skip, [$sku])) : [...$this->skip, $sku];
        $this->changed();
    }

    public function focusOn(?string $sku): void
    {
        $this->focus = $sku;
    }

    public function loadPreset(string $key): void
    {
        abort_unless(isset(StudioService::PRESETS[$key]), 404);
        $this->apply(app(StudioService::class)->preset($this->ctx(), $key)['inputs']);
        $this->loaded = StudioService::PRESETS[$key]['label'];
        $this->tab = 'workspace';
    }

    public function loadScenario(int $id): void
    {
        $s = $this->scenarioQuery()->findOrFail($id);
        $this->apply((array) $s->inputs);
        $this->loaded = $s->name;
        $this->tab = 'workspace';
    }

    public function deleteScenario(int $id): void
    {
        $s = $this->scenarioQuery()->findOrFail($id);
        abort_unless((int) $s->created_by === (int) auth()->id() || auth()->user()?->canManageUsers(), 403);
        $s->delete();
    }

    private function scenarioQuery()
    {
        return AssortmentScenario::where('tenant_id', Filament::getTenant()?->id)->where('store_id', $this->store)->where('category', $this->category);
    }

    private function apply(array $in): void
    {
        $in += StudioService::blank();
        $this->objective = $in['objective'];
        $this->room = $in['room'] === null ? null : (string) $in['room'];
        $this->maxSize = $in['max_size'] ? (int) $in['max_size'] : null;
        $this->minSize = $in['min_size'] ? (int) $in['min_size'] : null;
        $this->picks = array_values(array_map(fn ($p) => ['kind' => (string) $p['kind'], 'sku' => (string) $p['sku']], (array) $in['picks']));
        $this->protect = array_values((array) $in['protect']);
        $this->unprotect = array_values((array) $in['unprotect']);
        $this->skip = array_values((array) $in['skip']);
        $this->resultMemo = null;
    }

    private function changed(): void
    {
        $this->resultMemo = null;
        $this->loaded = null;
    }

    // ── What the page shows ───────────────────────────────────────────────────

    public function inputs(): array
    {
        return [
            'objective' => $this->objective ?: null, 'room' => ($this->room === null || $this->room === '') ? null : (float) $this->room,
            'max_size' => $this->maxSize ?: null, 'min_size' => $this->minSize ?: null,
            'picks' => $this->picks, 'protect' => $this->protect, 'unprotect' => $this->unprotect, 'skip' => $this->skip,
        ];
    }

    public function ctx(): ?array
    {
        if ($this->store === null || $this->category === null) {
            return null;
        }

        return $this->ctxMemo ??= app(StudioService::class)->context(Filament::getTenant(), (int) $this->store, (string) $this->category);
    }

    public function result(): ?array
    {
        $ctx = $this->ctx();

        return $ctx ? ($this->resultMemo ??= app(StudioService::class)->simulate($ctx, $this->inputs())) : null;
    }

    protected function getHeaderActions(): array
    {
        $svc = app(StudioService::class);

        return [
            Action::make('reset')->label('Reset to today')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn () => $this->ctx() !== null)
                ->action(fn () => $this->resetWorkspace()),
            Action::make('save')->label('Save scenario')->icon('heroicon-o-bookmark')->color('gray')
                ->visible(fn () => $this->ctx() !== null)
                ->form([TextInput::make('name')->label('Name')->required()->maxLength(120)->default(fn () => $this->loaded ?? 'My scenario')])
                ->action(function (array $data) use ($svc) {
                    $svc->save($this->ctx(), auth()->user(), $this->inputs(), (string) $data['name']);
                    Notification::make()->title('Scenario saved — compare it under Compare scenarios')->success()->send();
                }),
            Action::make('plan')->label('Make this the plan')->icon('heroicon-o-check')->color('success')
                ->visible(fn () => $this->ctx() !== null && $svc->canMakePlan(Filament::getTenant(), auth()->user()))
                ->disabled(fn () => ($r = $this->result()) === null || ($r['violations'] ?? []) !== []
                    || array_filter($r['changes'], fn ($c) => $c['kind'] !== AssortmentPlan::PROTECT) === [])
                ->modalHeading('Make this the range plan for the shelf')
                ->modalDescription(fn () => 'It replaces the plan proposed for this store and category'
                    . (TenantAssortment::plansLive(Filament::getTenant()) ? '' : ' (plans are in review: it stays in review until they open)')
                    . '. You then accept it as one reset task on the plan page. The figures are simulated, and it is measured 8 weeks after the reset like any other plan.')
                ->form([TextInput::make('name')->label('Name (optional)')->maxLength(120)->default(fn () => $this->loaded)])
                ->action(function (array $data) use ($svc) {
                    $plan = $svc->makePlan(Filament::getTenant(), auth()->user(), $this->ctx(), $this->inputs(), $data['name'] ?? null);
                    Notification::make()->title('This is now the plan for the shelf')->success()->send();
                    $this->redirect(AssortmentPlanPage::getUrl(['plan' => $plan->id]), navigate: true);
                }),
        ];
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $svc = app(StudioService::class);
        if (! static::opensFor()) {
            return ['closed' => true];
        }
        $ctx = $this->ctx();
        if ($ctx === null) {
            return ['closed' => false, 'ctx' => null, 'stores' => $svc->stores($tenant, auth()->user()), 'categories' => []];
        }
        $result = $this->result();
        $today = $svc->simulate($ctx, ['skip' => array_keys(array_filter($ctx['engine'], fn ($c) => $c['kind'] === AssortmentPlan::RECOVER))] + StudioService::blank());

        $columns = [];
        if ($this->tab === 'compare') {
            $columns[] = ['key' => 'today', 'label' => 'Today', 'about' => 'No change', 'summary' => $today['summary'], 'kind' => 'today'];
            $columns[] = ['key' => 'workspace', 'label' => $this->loaded ? 'Workspace: ' . $this->loaded : 'This workspace', 'about' => 'As you have it now',
                'summary' => $result['summary'], 'kind' => 'workspace'];
            foreach (StudioService::PRESETS as $k => $p) {
                $columns[] = ['key' => $k, 'label' => $p['label'], 'about' => $p['about'], 'summary' => $svc->preset($ctx, $k)['summary'], 'kind' => 'preset'];
            }
            foreach ($svc->scenarios($ctx) as $s) {
                $columns[] = ['key' => 's' . $s->id, 'id' => $s->id, 'label' => $s->name,
                    'about' => 'Saved ' . $s->created_at?->format('j M') . ($s->creator ? ' by ' . $s->creator->name : '')
                        . ($s->as_of_date?->toDateString() !== $ctx['as_of'] ? ' · on older data: load it to recalculate' : ''),
                    'summary' => $s->result['summary'] ?? [], 'kind' => 'saved',
                    'mine' => (int) $s->created_by === (int) auth()->id() || auth()->user()?->canManageUsers()];
            }
        }

        // The range list: changed and flagged first, then by sales; the rest on request.
        $picked = collect($this->picks)->keyBy('sku');
        $shelfNow = array_flip(array_map('strval', $result['shelf'] ?? []));
        $rows = [];
        foreach ($ctx['shelf']['skus'] as $sku => $p) {
            $sku = (string) $sku;
            $e = $ctx['engine'][$sku] ?? null;
            $rows[] = ['sku' => $sku, 'name' => $p['name'], 'sales' => $p['sales'], 'margin_rate' => $p['margin_rate'], 'stock' => $p['stock'],
                'protected' => $this->shelfProtection($ctx, $sku), 'default_protected' => ! empty($p['protected']),
                'flag' => $e['kind'] ?? null, 'off' => ! isset($shelfNow[$sku]), 'new' => false, 'picked' => $picked->has($sku)];
        }
        foreach ($this->picks as $pk) {
            if ($pk['kind'] === AssortmentPlan::ADD && isset($shelfNow[$pk['sku']]) && ! isset($ctx['shelf']['skus'][$pk['sku']])) {
                $c = $ctx['engine'][$pk['sku']] ?? $ctx['others'][$pk['sku']] ?? null;
                $rows[] = ['sku' => $pk['sku'], 'name' => $c['name'] ?? $pk['sku'], 'sales' => null, 'margin_rate' => $c['margin_rate'] ?? null, 'stock' => null,
                    'protected' => null, 'default_protected' => false, 'flag' => null, 'off' => false, 'new' => true, 'picked' => true];
            }
        }
        $term = mb_strtolower(trim($this->search));
        if ($term !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['name'] . ' ' . $r['sku']), $term)));
        }
        usort($rows, fn ($a, $b) => [! ($a['picked'] || $a['new']), $a['flag'] === null, -(float) $a['sales'], $a['sku']]
            <=> [! ($b['picked'] || $b['new']), $b['flag'] === null, -(float) $b['sales'], $b['sku']]);
        $total = count($rows);
        if (! $this->showAll && $term === '') {
            $rows = array_slice($rows, 0, self::ROWS);
        }

        // Opportunities not yet taken, valued on the shelf as it now stands.
        $opportunities = ['engine' => [], 'recover' => [], 'others' => []];
        $estimator = app(\App\Platform\Intelligence\Substitution\TransferEstimator::class);
        $shelfList = array_keys($shelfNow);
        foreach ($ctx['engine'] as $sku => $c) {
            if ($c['kind'] === AssortmentPlan::ADD && ! isset($shelfNow[$sku])) {
                $opportunities['engine'][] = $c + ['taken' => $estimator->estimate($sku, $shelfList, $ctx['products'], $ctx['observed'][$sku] ?? [])];
            } elseif ($c['kind'] === AssortmentPlan::RECOVER) {
                $opportunities['recover'][] = $c + ['skipped' => in_array($sku, $this->skip, true)];
            }
        }
        foreach ($ctx['others'] as $sku => $c) {
            if (! isset($shelfNow[$sku])) {
                $opportunities['others'][] = $c + ['taken' => $estimator->estimate($sku, $shelfList, $ctx['products'], $ctx['observed'][$sku] ?? [])];
            }
        }

        $plan = $ctx['plan'] ? AssortmentPlan::find($ctx['plan']['id']) : null;

        return [
            'closed'        => false,
            'ctx'           => $ctx,
            'r'             => $result,
            'today'         => $today['summary'],
            'stores'        => $svc->stores($tenant, auth()->user()),
            'categories'    => $svc->categories($tenant, (int) $this->store),
            'strategy'      => $svc->strategy($ctx, $this->inputs()),
            'rows'          => $rows,
            'total'         => $total,
            'opportunities' => $opportunities,
            'product'       => $this->focus ? $svc->product($ctx, $result, $this->focus) : null,
            'columns'       => $columns,
            'plan'          => $plan,
            'planUrl'       => $plan && AssortmentPlans::scoped()->whereKey($plan->id)->exists() ? AssortmentPlanPage::getUrl(['plan' => $plan->id]) : null,
            'currency'      => $tenant?->currencyCode(),
            'objectives'    => collect(CategoryStrategy::OBJECTIVES)->map(fn ($o) => $o['label'])->all(),
            'rooms'         => CategoryStrategy::ROOM_OPTIONS,
            'canPlan'       => $svc->canMakePlan($tenant, auth()->user()),
            'decisionUrl'   => fn ($id) => $id ? AssortmentDecision::getUrl(['decision' => $id]) : null,
        ];
    }

    /** Why a product on the shelf is protected in this scenario (null = it may go). */
    private function shelfProtection(array $ctx, string $sku): ?string
    {
        if (in_array($sku, $this->unprotect, true)) {
            return null;
        }
        if (in_array($sku, $this->protect, true)) {
            return 'Protected in this scenario';
        }

        return $ctx['shelf']['skus'][$sku]['protected'] ?? null;
    }
}
