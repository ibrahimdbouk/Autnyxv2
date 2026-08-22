<?php

namespace App\Console\Commands;

use App\Models\SftpConnection;
use App\Models\Tenant;
use App\Services\Sftp\SftpPollService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * M14 — polls active SFTP connections and auto-imports new flat files.
 */
class PollSftpCommand extends Command
{
    protected $signature = 'sftp:poll {--tenant= : Specific tenant ID} {--connection= : Specific connection ID}';

    protected $description = 'Poll active SFTP connections for new flat files and import them';

    public function handle(SftpPollService $service): int
    {
        if ($connId = $this->option('connection')) {
            $connection = SftpConnection::find((int) $connId);
            if (! $connection) {
                $this->error("Connection {$connId} not found.");
                return Command::FAILURE;
            }
            $n = $service->pollConnection($connection);
            $this->info("Connection {$connId}: {$n} file(s) imported.");
            return Command::SUCCESS;
        }

        $tenantIds = $this->option('tenant')
            ? [(int) $this->option('tenant')]
            : Tenant::pluck('id')->all();

        $total = 0;
        foreach ($tenantIds as $tenantId) {
            try {
                $total += $service->pollTenant($tenantId);
            } catch (\Throwable $e) {
                $this->error("Tenant {$tenantId} failed: {$e->getMessage()}");
                Log::error('[sftp:poll] tenant ' . $tenantId . ': ' . $e->getMessage());
            }
        }

        $this->info("Done. {$total} file(s) imported across " . count($tenantIds) . ' tenant(s).');
        return Command::SUCCESS;
    }
}
