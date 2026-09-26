<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebhookEndpointResource\Pages;
use App\Jobs\Webhooks\DeliverWebhookJob;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookDispatcher;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * W13 — outbound webhooks: tell another system (Power Automate, Zapier, Make,
 * the ERP team's receiver) when an investigation opens or is resolved, a
 * recovery is measured, a count is recorded or a finding opens.
 * Notification only — Autnyx never acts on a reply. Admin-gated.
 */
class WebhookEndpointResource extends Resource
{
    protected static ?string $model = WebhookEndpoint::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-bolt';

    protected static \UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Webhooks';

    protected static ?string $modelLabel = 'webhook';

    protected static ?int $navigationSort = 8;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return (bool) ($u && ($u->is_super_admin || $u->is_tenant_admin));
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tenant_id', Filament::getTenant()?->id);
    }

    public static function form(Schema $form): Schema
    {
        return $form->schema([
            TextInput::make('name')->required()->maxLength(120)->helperText('A label, e.g. "Power Automate — store ops".'),
            TextInput::make('url')->label('URL')->required()->maxLength(500)->url()
                ->rule(fn () => ApiConnectionResource::egressRule())
                ->helperText('https only. Each call is signed: check X-Autnyx-Signature with the secret shown when the webhook is created.'),
            CheckboxList::make('events')->options(WebhookEndpoint::EVENTS)->required()->columns(2)->columnSpanFull(),
            Select::make('min_severity')->label('New findings: from severity')->options(['high' => 'High', 'medium' => 'Medium and high', 'low' => 'All'])
                ->default('high')->required()->helperText('Only used for "New finding".'),
            Toggle::make('active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->weight('semibold')->description(fn (WebhookEndpoint $r) => $r->url),
                TextColumn::make('events')->badge()->formatStateUsing(fn ($state) => WebhookEndpoint::EVENTS[$state] ?? $state),
                IconColumn::make('active')->boolean(),
                TextColumn::make('last_success_at')->label('Last delivered')->since()->placeholder('never'),
                TextColumn::make('failure_streak')->label('Failures in a row')->numeric()
                    ->color(fn ($state) => $state > 0 ? 'danger' : null)
                    ->description(fn (WebhookEndpoint $r) => $r->disabled_reason),
            ])
            ->actions([
                Action::make('deliveries')->label('Log')->icon('heroicon-o-list-bullet')->color('gray')
                    ->modalHeading(fn (WebhookEndpoint $r) => 'Last deliveries — ' . $r->name)
                    ->modalContent(fn (WebhookEndpoint $r) => view('filament.webhooks.deliveries', [
                        'deliveries' => $r->deliveries()->latest('id')->limit(25)->get(),
                    ]))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('test')->label('Send test')->icon('heroicon-o-paper-airplane')->color('gray')
                    ->action(function (WebhookEndpoint $r) {
                        $d = app(WebhookDispatcher::class)->queue($r, 'ping', ['message' => 'Test from Autnyx. Signature: HMAC-SHA256 of "<t>.<body>" with your secret.'], dispatch: false);
                        $ok = app(WebhookDispatcher::class)->attempt($d->fresh());
                        $n = Notification::make()->title($ok ? 'Delivered' : 'Not delivered')
                            ->body($ok ? 'The endpoint answered ' . $d->fresh()->response_status . '.' : 'Answer: ' . ($d->fresh()->response_status ?: 'none') . '. See the log.');
                        ($ok ? $n->success() : $n->danger())->send();
                    }),
                Action::make('retry')->label('Retry failed')->icon('heroicon-o-arrow-path')->color('gray')
                    ->visible(fn (WebhookEndpoint $r) => $r->deliveries()->where('status', WebhookDelivery::STATUS_FAILED)->exists())
                    ->action(function (WebhookEndpoint $r) {
                        $n = 0;
                        foreach ($r->deliveries()->where('status', WebhookDelivery::STATUS_FAILED)->where('created_at', '>=', now()->subDays(7))->limit(200)->get() as $d) {
                            $d->forceFill(['status' => WebhookDelivery::STATUS_PENDING, 'attempts' => 0, 'next_attempt_at' => now()])->save();
                            DeliverWebhookJob::dispatch($d->id);
                            $n++;
                        }
                        $r->forceFill(['active' => true, 'disabled_reason' => null, 'failure_streak' => 0])->save();
                        Notification::make()->title("{$n} delivery(ies) queued again")->success()->send();
                    }),
                Action::make('rotate')->label('New secret')->icon('heroicon-o-key')->color('gray')->requiresConfirmation()
                    ->modalDescription('The old secret stops working at once. Update the receiving side with the new one.')
                    ->action(function (WebhookEndpoint $r) {
                        $secret = WebhookEndpoint::newSecret();
                        $r->forceFill(['secret' => $secret])->save();
                        Notification::make()->title('New signing secret')->body('Shown only now — copy it to the receiving side:  ' . $secret)->persistent()->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('No webhooks')
            ->emptyStateDescription('Send investigation, outcome, count and finding events to Power Automate, Zapier, Make or your own system.');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWebhookEndpoints::route('/'),
            'create' => Pages\CreateWebhookEndpoint::route('/create'),
            'edit'   => Pages\EditWebhookEndpoint::route('/{record}/edit'),
        ];
    }
}
