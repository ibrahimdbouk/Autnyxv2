<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * Living reference for the Autnyx UI system (Track U). Renders every shared
 * primitive from `resources/views/components/ui/*` so the team can see the
 * tokens + components in light and dark. Super-admins only.
 */
class UiKit extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-swatch';

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'UI Kit';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.ui-kit';

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->is_super_admin ?? false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->is_super_admin ?? false;
    }
}
