<?php

namespace App\Filament\Resources;
use App\Filament\Concerns\GatesResourceByScreen;

use App\Filament\Resources\InvestigationResource\Pages;
use App\Models\Investigation;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

class InvestigationResource extends Resource
{
    use GatesResourceByScreen;

    const SCREEN_KEY = 'investigations';
    protected static ?string $model = Investigation::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-magnifying-glass';

    protected static \UnitEnum|string|null $navigationGroup = 'Intelligence';

    protected static ?string $navigationLabel = 'Investigations';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool                                            { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool   { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = Filament::getTenant()?->id;
        return parent::getEloquentQuery()
            ->where('investigations.tenant_id', $tenantId)
            ->with(['assignedTeam', 'assignedUser']);
    }

    public static function getNavigationBadge(): ?string
    {
        $tenantId = Filament::getTenant()?->id;
        if (!$tenantId) return null;

        $count = Investigation::where('tenant_id', $tenantId)
            ->whereIn('status', [Investigation::STATUS_OPEN, Investigation::STATUS_IN_PROGRESS])
            ->whereIn('priority', [Investigation::PRIORITY_HIGH, Investigation::PRIORITY_CRITICAL])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    // WP7.4 (L6): the list page renders its own table (ListInvestigations),
    // so the resource-level table() configuration was never shown — removed.

    public static function getRelationManagers(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'       => Pages\ListInvestigations::route('/'),
            'investigate' => Pages\InvestigateInvestigation::route('/{record}/investigate'),
        ];
    }
}
