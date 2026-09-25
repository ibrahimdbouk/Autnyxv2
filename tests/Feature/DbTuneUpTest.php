<?php

namespace Tests\Feature;

use App\Console\Commands\JsonbCommand;
use App\Services\Ops\DatabaseBackup;
use App\Support\Database\OwnerConnection;
use App\Support\Database\WebStatementTimeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * W9 DB tune-up — foreign-key lookup indexes, jsonb for the queried JSON
 * columns, a statement timeout for web requests, and cleanup of one-off
 * repair tables.
 */
class DbTuneUpTest extends TestCase
{
    public function test_foreign_keys_that_are_looked_up_have_an_index(): void
    {
        $have = collect(DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = current_schema()"))->pluck('indexname');
        foreach (['anomalies_investigation_idx', 'anomalies_previous_episode_idx', 'inv_entities_anomaly_idx', 'inv_evidence_anomaly_idx',
            'actions_anomaly_idx', 'audit_logs_anomaly_idx', 'audit_logs_action_idx', 'quarantined_rows_import_idx', 'imports_tenant_feed_idx'] as $ix) {
            $this->assertContains($ix, $have->all(), $ix);
        }
        $this->assertNotContains('purchase_orders_tenant_id_po_number_index', $have->all(), 'redundant with the natural key');

    }

    public function test_the_queried_json_columns_are_jsonb_and_the_conversion_is_idempotent(): void
    {
        foreach (JsonbCommand::COLUMNS as [$table, $column]) {
            $type = DB::selectOne('SELECT data_type FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?', [$table, $column])->data_type;
            $this->assertSame('jsonb', $type, "{$table}.{$column}");
            $this->assertSame('already jsonb', JsonbCommand::convert($table, $column));
        }
        $this->assertSame('missing', JsonbCommand::convert('anomalies', 'no_such_column'));

        // Stored arrays still round-trip through the model casts.
        $t = $this->createTenant();
        $t->update(['settings' => ['autonomy' => ['execution_opt_in' => true]]]);
        $this->assertTrue($t->fresh()->settings['autonomy']['execution_opt_in']);
        $this->assertSame(1, DB::table('tenants')->where('id', $t->id)->whereRaw("settings @> '{\"autonomy\":{\"execution_opt_in\":true}}'")->count(), 'jsonb containment works');
    }

    public function test_web_requests_get_a_statement_timeout_and_workers_do_not(): void
    {
        config(['database.web_statement_timeout_ms' => 45000]);
        $c = DB::connection();
        try {
            $this->assertFalse(WebStatementTimeout::apply($c, console: true));
            $this->assertSame('0', DB::selectOne('SHOW statement_timeout')->statement_timeout);

            $this->assertTrue(WebStatementTimeout::apply($c, console: false));
            $this->assertSame('45s', DB::selectOne('SHOW statement_timeout')->statement_timeout);

            config(['database.web_statement_timeout_ms' => 0]);
            $this->assertFalse(WebStatementTimeout::apply($c, console: false), '0 disables');
        } finally {
            DB::statement('SET statement_timeout = 0');
        }
    }

    public function test_legacy_repair_tables_are_listed_and_only_dropped_with_a_fresh_backup(): void
    {
        DB::statement('CREATE TABLE wp35_dedupe_backup_probe (id int)');
        DB::statement('INSERT INTO wp35_dedupe_backup_probe VALUES (1), (2)');
        Storage::fake('backups');
        config(['backup.disk' => 'backups']);

        $this->artisan('db:drop-legacy-tables')->assertSuccessful()->expectsOutputToContain('wp35_dedupe_backup_probe: 2 rows (would drop)');
        $this->artisan('db:drop-legacy-tables --apply')->assertFailed()->expectsOutputToContain('run php artisan db:backup first');
        $this->assertNotNull(DB::selectOne("SELECT 1 AS x FROM pg_tables WHERE tablename = 'wp35_dedupe_backup_probe'"));

        $stamp = now('UTC')->subHours(2)->format(DatabaseBackup::STAMP);
        Storage::disk('backups')->put("backups/db/autnyx-{$stamp}Z.dump", 'x');
        Storage::disk('backups')->put("backups/db/autnyx-{$stamp}Z.json", '{}');
        $this->artisan('db:drop-legacy-tables --apply')->assertSuccessful()->expectsOutputToContain('Dropped wp35_dedupe_backup_probe');
        $this->assertNull(DB::selectOne("SELECT 1 AS x FROM pg_tables WHERE tablename = 'wp35_dedupe_backup_probe'"));
    }

    public function test_top_queries_and_the_maintenance_commands_run_as_the_owner(): void
    {
        foreach (['db:jsonb', 'db:drop-legacy-tables', 'db:top-queries'] as $cmd) {
            $this->assertTrue(OwnerConnection::needsOwner($cmd), $cmd);
        }
        $this->artisan('db:top-queries --limit=3')->assertSuccessful();
    }
}
