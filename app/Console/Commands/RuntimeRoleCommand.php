<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * WP8.1 (audit L13) — create or refresh the least-privilege runtime role.
 *
 * The role may connect, use the public schema, read and write rows of every
 * table, use sequences and temporary tables — nothing else: no DDL, no
 * ownership, no role or database creation. Default privileges make tables
 * the owner creates later (migrations) usable by it too.
 *
 * Runs as the owner (OwnerConnection). The password is read from
 * DB_RUNTIME_PASSWORD and never printed. `--check` changes nothing and
 * reports what the role can do; it fails when the role could change the
 * schema or lacks row access to any table.
 */
class RuntimeRoleCommand extends Command
{
    protected $signature = 'db:runtime-role {--role=autnyx_app : The runtime role name} {--check : Report only}';

    protected $description = 'Create or refresh the least-privilege runtime database role (CRUD only)';

    public function handle(): int
    {
        $role = (string) $this->option('role');
        if (! preg_match('/^[a-z_][a-z0-9_]{2,62}$/', $role)) {
            $this->error('The role name must be lower-case letters, digits and underscores.');

            return self::FAILURE;
        }

        if (! $this->option('check')) {
            $password = (string) env('DB_RUNTIME_PASSWORD', '');
            if (strlen($password) < 24) {
                $this->error('Set DB_RUNTIME_PASSWORD (24+ characters) in the environment first. It is never printed.');

                return self::FAILURE;
            }
            $this->provision($role, $password);
            $this->info("Role {$role} is provisioned.");
        }

        return $this->report($role);
    }

    private function provision(string $role, string $password): void
    {
        $pdo      = DB::connection()->getPdo();
        $quoted   = $pdo->quote($password);
        $database = DB::connection()->getDatabaseName();
        $owner    = (string) DB::selectOne('SELECT current_user AS u')->u;

        $exists = DB::selectOne('SELECT 1 AS x FROM pg_roles WHERE rolname = ?', [$role]);
        DB::statement(($exists ? 'ALTER' : 'CREATE') . " ROLE {$role} WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION PASSWORD {$quoted}");

        // PostgreSQL 15+ no longer lets PUBLIC create in public; make sure,
        // where the owner is allowed to say so (best effort on managed hosts).
        try {
            DB::transaction(fn () => DB::statement('REVOKE CREATE ON SCHEMA public FROM PUBLIC'));
        } catch (\Throwable $e) {
            $this->warn('Could not revoke CREATE on schema public from PUBLIC (not the schema owner) — checked below.');
        }

        foreach ([
            "REVOKE ALL ON SCHEMA public FROM {$role}",
            "GRANT CONNECT, TEMPORARY ON DATABASE \"{$database}\" TO {$role}",
            "GRANT USAGE ON SCHEMA public TO {$role}",
            "GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$role}",
            "GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO {$role}",
            "ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}",
            "ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO {$role}",
            // Row access on the tables only — no TRUNCATE, REFERENCES or TRIGGER.
            "REVOKE TRUNCATE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA public FROM {$role}",
        ] as $sql) {
            DB::statement($sql);
        }
    }

    private function report(string $role): int
    {
        $r = DB::selectOne('SELECT rolsuper, rolcreaterole, rolcreatedb, rolbypassrls, rolcanlogin FROM pg_roles WHERE rolname = ?', [$role]);
        if (! $r) {
            $this->error("Role {$role} does not exist.");

            return self::FAILURE;
        }

        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
        $noRows = [];
        foreach ($tables as $t) {
            foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $priv) {
                if (! DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$role, 'public.' . $t->tablename, $priv])->ok) {
                    $noRows[] = "{$t->tablename} ({$priv})";
                }
            }
        }
        $owns      = (int) DB::selectOne("SELECT COUNT(*) AS n FROM pg_class c JOIN pg_roles r ON r.oid = c.relowner
            JOIN pg_namespace ns ON ns.oid = c.relnamespace WHERE r.rolname = ? AND ns.nspname = 'public'", [$role])->n;   // its own temp tables don't count
        $canCreate = (bool) DB::selectOne("SELECT has_schema_privilege(?, 'public', 'CREATE') AS ok", [$role])->ok;
        $powers    = array_keys(array_filter(['superuser' => $r->rolsuper, 'createrole' => $r->rolcreaterole, 'createdb' => $r->rolcreatedb, 'bypassrls' => $r->rolbypassrls]));

        $this->line("Tables: " . count($tables) . '; missing row privileges: ' . (count($noRows) ?: 'none'));
        foreach (array_slice($noRows, 0, 20) as $m) {
            $this->line("  - {$m}");
        }
        $this->line('Can create objects in public: ' . ($canCreate ? 'YES' : 'no') . '; owns objects: ' . $owns
            . '; role powers: ' . ($powers ? implode(', ', $powers) : 'none') . '; can log in: ' . ($r->rolcanlogin ? 'yes' : 'no'));

        $ok = $noRows === [] && ! $canCreate && $owns === 0 && $powers === [];
        $ok ? $this->info("{$role}: least privilege — OK.") : $this->error("{$role}: NOT least privilege or missing access.");

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
