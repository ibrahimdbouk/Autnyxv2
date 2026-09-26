<?php

namespace App\Console\Commands;

use App\Mail\ValueReportMail;
use App\Models\Investigation;
use App\Models\InvestigationOutcome;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reporting\ReportDataService;
use App\Support\Tenancy\TenantClock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * W11 — the monthly "Value delivered" report, to each tenant's admins (and the
 * tenant notification address): last month on the tenant's own calendar, sent
 * once (tenants.settings.value_report_sent = 'YYYY-MM'). Runs in the nightly
 * chain, so it goes out on the first night of the month; a month with nothing
 * found or measured is skipped. settings.value_report_off stops it.
 */
class SendValueReportCommand extends Command
{
    protected $signature = 'reports:value-monthly {--tenant= : Only this tenant} {--force : Send even if already sent for that month}';

    protected $description = 'Send last month\'s Value Delivered report to tenant admins (once per month)';

    public function handle(ReportDataService $reports): int
    {
        $failures = 0;
        $tenants = Tenant::query()->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $t) => $q->whereKey((int) $t))->get();

        foreach ($tenants as $tenant) {
            try {
                $settings = (array) ($tenant->settings ?? []);
                if (! empty($settings['value_report_off'])) {
                    continue;
                }
                $tz = TenantClock::timezone($tenant->id);
                $month = now($tz)->subMonthNoOverflow()->startOfMonth();
                $key = $month->format('Y-m');
                if (($settings['value_report_sent'] ?? null) === $key && ! $this->option('force')) {
                    continue;
                }
                $from = $month->copy()->setTimezone('UTC');
                $to   = $month->copy()->endOfMonth()->setTimezone('UTC');

                $hasActivity = Investigation::where('tenant_id', $tenant->id)->whereBetween('opened_at', [$from, $to])->exists()
                    || InvestigationOutcome::where('tenant_id', $tenant->id)->whereBetween('recorded_at', [$from, $to])->exists();

                $recipients = $this->recipients($tenant);
                if ($hasActivity && $recipients !== []) {
                    $payload = $reports->build('value', $tenant->id, $from, $to);
                    $payload['period'] = $month->format('F Y');
                    $filename = 'autnyx-value-' . $key . '.pdf';
                    foreach ($recipients as $email) {
                        Mail::to($email)->queue(new ValueReportMail($tenant, $month->format('F Y'), $payload, $filename));
                    }
                    $this->line("  {$tenant->name}: {$month->format('F Y')} → " . count($recipients) . ' recipient(s)');
                } else {
                    $this->line("  {$tenant->name}: nothing to report for {$month->format('F Y')}" . ($recipients === [] ? ' (no recipients)' : ''));
                }

                $settings['value_report_sent'] = $key;
                $tenant->forceFill(['settings' => $settings])->save();
            } catch (\Throwable $e) {
                report($e);
                Log::error("[reports:value-monthly] tenant {$tenant->id}: {$e->getMessage()}");
                $failures++;
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int,string> */
    private function recipients(Tenant $tenant): array
    {
        $out = [];
        if (! empty($tenant->notification_email)) {
            $out[strtolower($tenant->notification_email)] = $tenant->notification_email;
        }
        foreach (User::where('tenant_id', $tenant->id)->where('is_tenant_admin', true)->whereNotNull('email')->pluck('email') as $e) {
            $out[strtolower($e)] ??= $e;
        }

        return array_values($out);
    }
}
