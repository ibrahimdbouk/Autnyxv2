<?php

namespace App\Filament\Resources;

use App\Filament\Pages\AssortmentDecision;
use App\Filament\Resources\AssortmentDecisionResource\Pages;
use App\Filament\Support\AssortmentDecisionActions;
use App\Models\AssortmentGap;
use App\Models\Tenant;
use App\Services\Org\OrgDirectory;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Assortment — the range decisions: the queue of what is worth doing now
 * (ranked by the middle of the value range × confidence), the tasks accepted
 * decisions became, their measured results, and what was rejected. Shadow
 * decisions (validation gate not passed) never appear here.
 */
class AssortmentDecisionResource extends Resource
{
    const APP_KEY = Tenant::APP_ASSORTMENT;

    const SCREEN_KEY = 'assortment_decisions';

    protected static ?string $model = AssortmentGap::class;

    protected static ?string $slug = 'assortment-decisions';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-queue-list';

    protected static \UnitEnum|string|null $navigationGroup = 'Assortment';

    protected static ?string $navigationLabel = 'Decisions';

    protected static ?string $modelLabel = 'range decision';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        $tenant = Filament::getTenant();
        $user   = auth()->user();

        return $tenant instanceof Tenant && $user && $tenant->hasApp(Tenant::APP_ASSORTMENT) && $user->canSeeScreen(self::SCREEN_KEY);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny() && (int) $record->tenant_id === (int) Filament::getTenant()?->id;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $tenantId = Filament::getTenant()?->id;
        if (! $tenantId) {
            return null;
        }
        $n = static::getEloquentQuery()->where('status', AssortmentGap::STATUS_OPEN)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()
            ->where('assortment_gaps.tenant_id', Filament::getTenant()?->id)
            ->where('assortment_gaps.status', '<>', AssortmentGap::STATUS_SHADOW)
            ->with(['store:id,name', 'product:id,name,category', 'assignee:id,name']);

        $user  = auth()->user();
        $scope = $user ? app(OrgDirectory::class)->storeScope($user) : null;
        if ($scope !== null) {
            $q->whereIn('assortment_gaps.store_id', $scope);
        }

        return $q;
    }

    public static function table(Table $table): Table
    {
        $currency = Filament::getTenant()?->currencyCode();

        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByRaw('assortment_gaps.value_mid * assortment_gaps.confidence DESC'))
            ->recordUrl(fn (AssortmentGap $r) => AssortmentDecision::getUrl(['decision' => $r->id]))
            ->columns([
                TextColumn::make('type')->label('Decision')->badge()
                    ->formatStateUsing(fn ($state) => AssortmentGap::TYPES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        AssortmentGap::TYPE_ADD             => 'success',
                        AssortmentGap::TYPE_DELIST          => 'warning',
                        AssortmentGap::TYPE_STOCKOUT_HIDDEN => 'danger',
                        default                             => 'gray',
                    }),
                TextColumn::make('product.name')->label('Product')->weight('semibold')->wrap()
                    ->description(fn (AssortmentGap $r) => $r->sku)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($w) => $w
                        ->where('assortment_gaps.sku', 'ilike', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'ilike', "%{$search}%")))),
                TextColumn::make('store.name')->label('Store')->sortable()
                    ->description(fn (AssortmentGap $r) => $r->product?->category),
                TextColumn::make('product.category')->label('Category')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('value_mid')->label('Value a year')->sortable()
                    ->formatStateUsing(fn ($state, AssortmentGap $r) => $r->valueRange($currency)),
                TextColumn::make('confidence_tier')->label('Confidence')->badge()
                    ->color(fn ($state) => match ($state) {
                        AssortmentGap::TIER_ESTABLISHED => 'success',
                        AssortmentGap::TIER_LIKELY      => 'info',
                        default                         => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => ucfirst((string) $state)),
                TextColumn::make('assignee.name')->label('Owner')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('due_at')->label('Due')->date('j M')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('task_status')->label('Task')->badge()->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state) => match ($state) {
                        AssortmentGap::TASK_TO_DO     => 'To do',
                        AssortmentGap::TASK_DONE      => 'Done',
                        AssortmentGap::TASK_CANCELLED => 'Cancelled',
                        default                       => '—',
                    }),
                TextColumn::make('measurement')->label('Measured')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (AssortmentGap $r) => $r->measured_at ? (\App\Support\Money::compact((float) ($r->measurement['uplift_per_year'] ?? 0), $currency) . ' a year') : null),
            ])
            ->filters([
                SelectFilter::make('type')->label('Decision')->options(AssortmentGap::TYPES)->multiple(),
                SelectFilter::make('store_id')->label('Store')->relationship('store', 'name')->searchable()->preload(),
                SelectFilter::make('category')->label('Category')
                    ->options(fn () => DB::table('products')->where('tenant_id', Filament::getTenant()?->id)
                        ->whereNotNull('category')->distinct()->orderBy('category')->pluck('category', 'category')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('product', fn ($p) => $p->where('category', $data['value'])) : $query),
                SelectFilter::make('confidence_tier')->label('Confidence')->options([
                    AssortmentGap::TIER_ESTABLISHED => 'Established',
                    AssortmentGap::TIER_LIKELY      => 'Likely',
                    AssortmentGap::TIER_SPECULATIVE => 'Speculative',
                ]),
            ])
            ->actions([
                AssortmentDecisionActions::accept(),
                AssortmentDecisionActions::reject(),
                AssortmentDecisionActions::done(),
                Action::make('open')->label('Why')->icon('heroicon-o-information-circle')->color('gray')
                    ->url(fn (AssortmentGap $r) => AssortmentDecision::getUrl(['decision' => $r->id])),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    AssortmentDecisionActions::bulkAccept(),
                    AssortmentDecisionActions::bulkReject(),
                ]),
            ])
            ->emptyStateHeading(fn () => \App\Services\Assortment\TenantAssortment::isLive(Filament::getTenant())
                ? 'Nothing here' : 'Range decisions are being checked')
            ->emptyStateDescription(fn () => \App\Services\Assortment\TenantAssortment::isLive(Filament::getTenant())
                ? 'Decisions appear here after the nightly run.'
                : 'They appear here once they have been reviewed with your team (Assortment → Validation).');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAssortmentDecisions::route('/'),
        ];
    }
}
