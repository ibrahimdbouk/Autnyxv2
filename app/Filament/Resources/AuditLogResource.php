<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditLogResource\Pages;
use App\Filament\Resources\InvestigationResource;
use App\Models\AuditLog;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * AuditLogResource — the global, read-only audit log. Surfaces every recorded
 * event (status changes, assignments, escalations, actions, AI generation,
 * outcomes, watches, snoozes, suppressions, bulk operations, and comments —
 * including comment text and whether a comment arrived via the web or by email
 * reply). Tenant-scoped and restricted to admins.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static \UnitEnum|string|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Audit Log';

    protected static ?int $navigationSort = 20;

    /** event_type → [label, color] */
    public const EVENT_META = [
        'status_changed'   => ['Status changed', 'info'],
        'assigned'         => ['Assigned', 'info'],
        'reassigned'       => ['Reassigned', 'info'],
        'escalated'        => ['Escalated', 'warning'],
        'action_created'   => ['Action created', 'gray'],
        'action_completed' => ['Action completed', 'success'],
        'action_cancelled' => ['Action cancelled', 'gray'],
        'evidence_added'   => ['Evidence added', 'gray'],
        'comment_added'    => ['Comment', 'primary'],
        'ai_generated'     => ['AI narrative', 'purple'],
        'fp_dismissed'     => ['False positive', 'danger'],
        'priority_changed' => ['Priority changed', 'warning'],
        'watch_started'    => ['Watch started', 'info'],
        'watch_ended'      => ['Watch ended', 'gray'],
        'snoozed'          => ['Snoozed', 'warning'],
        'unsnoozed'        => ['Unsnoozed', 'gray'],
        'suppressed'       => ['Suppressed', 'warning'],
        'suppression_ended'=> ['Suppression ended', 'gray'],
        'bulk_action'      => ['Bulk action', 'info'],
        'outcome_measured' => ['Outcome measured', 'success'],
    ];

    public static function canViewAny(): bool
    {
        return auth()->user()?->canViewAuditLogs() ?? false;
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('audit_logs.tenant_id', Filament::getTenant()?->id)
            ->with(['user', 'investigation']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime('M j, Y H:i')
                    ->description(fn (AuditLog $r) => $r->created_at?->diffForHumans())
                    ->sortable(),

                TextColumn::make('event_type')
                    ->label('Event')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::EVENT_META[$state][0] ?? ucwords(str_replace('_', ' ', (string) $state)))
                    ->color(fn ($state) => self::EVENT_META[$state][1] ?? 'gray'),

                TextColumn::make('actor')
                    ->label('Actor')
                    ->state(fn (AuditLog $r) => $r->user?->name ?? 'System')
                    ->badge()
                    ->color(fn (AuditLog $r) => $r->user ? 'gray' : 'info'),

                TextColumn::make('channel')
                    ->label('Via')
                    ->state(fn (AuditLog $r) => (is_array($r->new_value) && ($r->new_value['source'] ?? null) === 'email') ? 'Email' : '—')
                    ->badge()
                    ->color('warning')
                    ->placeholder('—'),

                TextColumn::make('description')
                    ->label('Detail')
                    ->wrap()
                    ->limit(160)
                    ->tooltip(fn (AuditLog $r) => strlen((string) $r->description) > 160 ? $r->description : null)
                    ->searchable(),

                TextColumn::make('investigation.title')
                    ->label('Investigation')
                    ->limit(36)
                    ->placeholder('—')
                    ->url(fn (AuditLog $r) => $r->investigation_id
                        ? InvestigationResource::getUrl('investigate', ['record' => $r->investigation_id])
                        : null)
                    ->color('primary'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('event_type')
                    ->label('Event')
                    ->options(collect(self::EVENT_META)->mapWithKeys(fn ($m, $k) => [$k => $m[0]])->toArray()),

                SelectFilter::make('user_id')
                    ->label('Actor')
                    ->options(fn () => User::where('tenant_id', Filament::getTenant()?->id)->orderBy('name')->pluck('name', 'id')->toArray()),

                SelectFilter::make('period')
                    ->label('Period')
                    ->options([
                        'today' => 'Today',
                        '7d'    => 'Last 7 days',
                        '30d'   => 'Last 30 days',
                    ])
                    ->query(function (Builder $query, array $data) {
                        $since = match ($data['value'] ?? null) {
                            'today' => now()->startOfDay(),
                            '7d'    => now()->subDays(7),
                            '30d'   => now()->subDays(30),
                            default => null,
                        };
                        if ($since) {
                            $query->where('created_at', '>=', $since);
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
