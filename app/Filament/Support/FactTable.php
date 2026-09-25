<?php

namespace App\Filament\Support;

use App\Models\Store;
use App\Models\Supplier;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * WP6.4 (audit H32) — list pages over the fact tables (sales lines, stock
 * snapshots, POs, returns) that stay fast at millions of rows:
 *
 *  - no COUNT(*) of the table on every render (simple pagination);
 *  - a default date window, so a page never sorts the whole history;
 *  - filter options from the master tables (stores, suppliers), never a
 *    DISTINCT over the fact table;
 *  - a store search that goes through the stores master and the store_id
 *    index instead of ILIKE on every row's location text.
 */
final class FactTable
{
    public static function configure(Table $table): Table
    {
        return $table->paginationMode(PaginationMode::Simple);
    }

    private static function tenantId(): ?int
    {
        return Filament::getTenant()?->id;
    }

    /** Store filter over store_id, options from the stores master. */
    public static function storeFilter(string $label = 'Store'): SelectFilter
    {
        return SelectFilter::make('store_id')
            ->label($label)
            ->options(fn () => Store::query()->where('tenant_id', self::tenantId())->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()
            ->multiple();
    }

    /** Supplier filter on the PO's supplier text, options from the suppliers master. */
    public static function supplierFilter(): SelectFilter
    {
        return SelectFilter::make('supplier')
            ->label('Supplier')
            ->options(fn () => Supplier::query()->where('tenant_id', self::tenantId())->orderBy('name')->pluck('name', 'name')->all())
            ->searchable()
            ->multiple();
    }

    /**
     * A date range filter; with $days it starts on the last $days days (clearable).
     */
    public static function dateRange(string $name, string $column, string $label, ?int $days = null): Filter
    {
        return Filter::make($name)
            ->label($label)
            ->form([
                DatePicker::make('from')->label('From')->default($days !== null ? now()->subDays($days)->toDateString() : null),
                DatePicker::make('until')->label('Until'),
            ])
            ->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->where($column, '>=', $d))
                ->when($data['until'] ?? null, fn (Builder $q, $d) => $q->where($column, '<=', $d)))
            ->indicateUsing(function (array $data) use ($label): ?string {
                $from = $data['from'] ?? null;
                $until = $data['until'] ?? null;
                if (! $from && ! $until) {
                    return null;
                }

                return $label . ': ' . ($from ?: '…') . ' – ' . ($until ?: 'today');
            });
    }

    /** Search the location column through the stores master (name or code) → store_id. */
    public static function searchStore(Builder $query, string $search): Builder
    {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';

        return $query->whereIn('store_id', Store::query()->where('tenant_id', self::tenantId())
            ->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like))
            ->select('id'));
    }

    /** A receipt / PO number is looked up exactly (index), or by prefix. */
    public static function searchExact(string $column): \Closure
    {
        return fn (Builder $query, string $search): Builder => $query->where(function ($q) use ($column, $search) {
            $q->where($column, trim($search))
                ->orWhere($column, 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($search)) . '%');
        });
    }
}
