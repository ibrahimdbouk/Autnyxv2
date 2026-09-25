<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Database\OwnerConnection;
use App\Support\Security\SecretsInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * WP8.1 (audit L13) — the secrets inventory against the running environment:
 * which secrets are set (never their values) and the hygiene problems that
 * matter: debug mode on, a weak app key, the seeded admin still on its old
 * default password, the app connecting as the database owner.
 */
class SecretsCheckCommand extends Command
{
    protected $signature = 'security:secrets';

    protected $description = 'Report which platform secrets are set (never values) and flag secret hygiene problems';

    public function handle(): int
    {
        $rows = [];
        foreach (SecretsInventory::SECRETS as $key => [$purpose, $where, $days, $path]) {
            $set = $path !== null ? filled(config($path)) : filled(getenv($key) ?: ($_ENV[$key] ?? null));
            $rows[] = [$key, $set ? 'set' : '—', $days ? "every {$days}d" : 'on exposure', $where];
        }
        $this->table(['Secret', 'Status', 'Rotate', 'Lives in'], $rows);

        $issues = [];
        if (app()->isProduction() && config('app.debug')) {
            $issues[] = 'APP_DEBUG is on in production.';
        }
        $key = (string) config('app.key');
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if ($raw === false || strlen((string) $raw) < 32) {
            $issues[] = 'APP_KEY is missing or shorter than 32 bytes.';
        }
        $admin = User::where('email', 'admin@autnyx.io')->first();
        if ($admin && Hash::check(SecretsInventory::OLD_ADMIN_DEFAULT, (string) $admin->password)) {
            $issues[] = 'admin@autnyx.io still has the old seeded default password — change it now.';
        }
        try {
            $me = (string) DB::selectOne('SELECT current_user AS u')->u;
            $owns = (int) DB::selectOne("SELECT COUNT(*) AS n FROM pg_tables WHERE schemaname = 'public' AND tableowner = current_user")->n;
            if ($owns > 0) {
                $issues[] = "The app connects as the database owner ({$me}, owns {$owns} tables) — roll out the runtime role (docs/database-roles.md).";
            }
        } catch (\Throwable $e) {
            // not PostgreSQL / no connection: nothing to judge
        }
        if (! OwnerConnection::configured()) {
            $issues[] = 'DB_OWNER_USERNAME is not set: migrations and the app share one role.';
        }

        if ($issues === []) {
            $this->info('Secret hygiene: no problems found.');

            return self::SUCCESS;
        }
        foreach ($issues as $i) {
            $this->warn('• ' . $i);
        }

        return self::FAILURE;
    }
}
