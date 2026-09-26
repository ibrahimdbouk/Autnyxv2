<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatesPageByScreen;
use App\Models\AgentRun;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Agents\SupplierPrepAgent;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * Supplier Prep (Agent #5) — negotiation evidence packs.
 *
 * Lists the tenant's suppliers; opening one builds (or shows) an AI negotiation
 * brief from that supplier's deterministic PO scorecard and linked supply
 * anomalies. The agent drafts; the buyer negotiates.
 */
class SupplierPrep extends Page
{
    use \App\Filament\Concerns\SanitizesUrlState;   // WP7.2

    protected function urlRules(): array
    {
        return ['supplier' => 'int'];
    }

    use GatesPageByScreen;

    const SCREEN_KEY = 'supplier_prep';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-briefcase';

    protected static \UnitEnum|string|null $navigationGroup = 'Root Cause';

    protected static ?string $navigationLabel = 'Supplier Prep';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'supplier-prep';

    protected string $view = 'filament.pages.supplier-prep';

    /** Drill-down: the supplier currently being prepared. */
    #[Url]
    public $supplier = null;

    public function getTitle(): string
    {
        return 'Supplier Prep';
    }

    /**
     * @return array<string,mixed>
     */
    public function getData(): array
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return ['ready' => false];
        }

        // Drill-down: one supplier's pack.
        if ($this->supplier) {
            $supplier = Supplier::where('tenant_id', $tenantId)->find($this->supplier);
            if (! $supplier) {
                return ['ready' => true, 'mode' => 'list', 'suppliers' => $this->supplierList($tenantId), 'back_url' => self::getUrl()];
            }

            $run = AgentRun::where('tenant_id', $tenantId)
                ->where('agent_key', AgentRun::KEY_SUPPLIER_PREP)
                ->where('subject_type', 'supplier')
                ->where('subject_id', (string) $this->supplier)
                ->whereIn('status', [AgentRun::STATUS_COMPLETE, AgentRun::STATUS_FAILED])
                ->latest('id')
                ->first();

            return [
                'ready'    => true,
                'mode'     => 'detail',
                'back_url' => self::getUrl(),
                'name'     => $supplier->name,
                'has'      => (bool) $run,
                'failed'   => $run?->isFailed() ?? false,
                'pack'     => $run?->out('pack', []) ?? [],
                'headline' => $run?->out('headline'),
                'summary'  => $run?->out('summary'),
                'talking_points' => $run?->out('talking_points', []) ?? [],
                'asks'     => $run?->out('asks', []) ?? [],
                'leverage' => $run?->out('leverage', []) ?? [],
                'data_points' => $run?->out('data_points', []) ?? [],
                'confidence' => $run?->confidence,
                'generated_ago' => $run ? optional($run->created_at)->diffForHumans() : null,
            ];
        }

        return ['ready' => true, 'mode' => 'list', 'suppliers' => $this->supplierList($tenantId), 'back_url' => self::getUrl()];
    }

    /** @return array<int,array> */
    private function supplierList(int $tenantId): array
    {
        $poCounts = PurchaseOrder::where('tenant_id', $tenantId)
            ->selectRaw('supplier_id, count(*) as c')
            ->whereNotNull('supplier_id')
            ->groupBy('supplier_id')
            ->pluck('c', 'supplier_id');

        return Supplier::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'specialization', 'lead_time_days'])
            ->map(fn ($s) => [
                'id'             => $s->id,
                'name'           => $s->name,
                'specialization' => $s->specialization,
                'lead'           => $s->lead_time_days,
                'pos'            => (int) ($poCounts[$s->id] ?? 0),
                'url'            => self::getUrl(['supplier' => $s->id]),
            ])
            ->all();
    }

    /** Build (or rebuild) the pack for the supplier in view. */
    public function build(): void
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId || ! $this->supplier) {
            return;
        }

        $run = app(SupplierPrepAgent::class)->prepare($tenantId, $this->supplier, auth()->id());

        if ($run->isFailed()) {
            Notification::make()->title('Could not build the pack')
                ->body('The AI service did not respond. Please try again in a moment.')
                ->danger()->send();

            return;
        }

        Notification::make()->title('Negotiation pack ready')->success()->send();
    }
}
