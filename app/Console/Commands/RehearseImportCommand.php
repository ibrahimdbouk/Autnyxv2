<?php

namespace App\Console\Commands;

use App\Models\Import;
use App\Services\DataQuality\Reasons;
use App\Services\Import\ImportProcessorService;
use Illuminate\Console\Command;

/**
 * W9 (WP9.1) — dress rehearsal of an import: re-screen its stored file with
 * its saved mapping through today's firewall, with the gates as configured
 * and with every gate on, plus the feed and personal-data checks. Writes
 * nothing. Use it before switching a gate on, or on a tenant's first real
 * files, to see what WOULD be held or quarantined.
 */
class RehearseImportCommand extends Command
{
    protected $signature = 'imports:rehearse {import* : Import ID(s)} {--limit= : Stop after this many rows} {--json : Machine-readable output}';

    protected $description = 'Re-screen stored import files through the firewall without writing anything';

    public function handle(ImportProcessorService $processor): int
    {
        $all = [];
        foreach ((array) $this->argument('import') as $id) {
            $import = Import::find((int) $id);
            if (! $import) {
                $this->error("Import #{$id} not found.");
                continue;
            }
            try {
                $r = $processor->rehearse($import, $this->option('limit') ? (int) $this->option('limit') : null);
            } catch (\Throwable $e) {
                $this->error(str_starts_with($e->getMessage(), 'Import #') ? $e->getMessage() : "Import #{$id}: {$e->getMessage()}");
                continue;
            }
            $all[] = $r;
            if (! $this->option('json')) {
                $this->report($r);
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        return $all === [] ? self::FAILURE : self::SUCCESS;
    }

    private function report(array $r): void
    {
        $i = $r['import'];
        $this->newLine();
        $this->info("Import #{$i['id']} — {$i['file']} ({$i['data_type']}, feed {$i['feed']}, {$i['status']}) — "
            . number_format($r['rows']) . ' rows' . ($r['limited'] ? ' (limited)' : '') . " in {$r['seconds']}s");

        foreach ($r['modes'] as $mode => $m) {
            $this->line(sprintf('  %-11s %s  passed %s · quarantined %s · cleansed %s — %s', $mode === 'configured' ? 'as set:' : 'all gates:',
                strtoupper($m['state']), number_format($m['passed']), number_format($m['quarantined']), number_format($m['cleansed']), $m['decision']));
            foreach ($m['rejections'] as $code => $n) {
                $rows = implode(', ', array_map(fn ($s) => $s['row'], $m['samples'][$code] ?? []));
                $this->line("      ✗ " . Reasons::label($code) . ": {$n}" . ($rows ? " (e.g. rows {$rows})" : ''));
            }
            foreach ($m['warnings'] as $code => $n) {
                $this->line("      ! " . Reasons::label($code) . ": {$n}");
            }
        }

        $f = $r['feed'];
        if ($f['issues'] !== []) {
            foreach ($f['issues'] as $issue) {
                $this->line("  feed: {$issue['severity']} — {$issue['detail']}");
            }
        } else {
            $this->line('  feed: ' . ($f['established'] ? 'within its usual volume and columns' : "not enough history yet ({$f['batches_seen']} batch(es))"));
        }
        $this->line('  personal data: ' . ($r['pii'] ? implode(', ', array_map(fn ($c, $k) => "{$c} ({$k})", array_keys($r['pii']), $r['pii'])) . ' — would be masked' : 'none found'));
    }
}
