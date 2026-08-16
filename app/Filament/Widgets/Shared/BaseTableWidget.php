<?php

namespace App\Filament\Widgets\Shared;

use Filament\Tables;
use Filament\Widgets\TableWidget;

/**
 * Base class for all Autnyx table widgets.
 *
 * Drop onto any page and it renders the table.
 * Override table() in the subclass to define columns, filters, and actions.
 *
 * Usage:
 *   protected function table(Tables\Table $table): Tables\Table
 *   {
 *       return $table
 *           ->query(...)
 *           ->columns([...]);
 *   }
 */
abstract class BaseTableWidget extends TableWidget
{
    protected static ?string $pollingInterval = null;

    protected int | string | array $columnSpan = 'full';

    /**
     * Default page size for embedded tables.
     */
    protected static int $defaultPaginationPageOption = 10;
}
