<?php

namespace App\Console\Commands;

use App\Casts\EncryptedArrayWithLegacy;
use App\Models\OutboundTarget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * outbound:encrypt-secrets — WP2.2 (audit M6) one-off repair: re-save every
 * outbound target whose credential config is still stored as plaintext JSON so
 * it is encrypted at rest. Idempotent.
 */
class EncryptOutboundSecretsCommand extends Command
{
    protected $signature = 'outbound:encrypt-secrets {--dry : Report without writing}';

    protected $description = 'Encrypt outbound-target credentials that are still stored in plaintext';

    public function handle(): int
    {
        $count = 0;
        foreach (DB::table('outbound_targets')->select('id', 'config')->orderBy('id')->cursor() as $row) {
            if (! EncryptedArrayWithLegacy::isPlaintext($row->config)) {
                continue;
            }
            $count++;
            if (! $this->option('dry')) {
                $target = OutboundTarget::find($row->id);
                $target->config = $target->config; // read legacy JSON, write encrypted
                $target->save();
            }
        }

        $this->info(($this->option('dry') ? '[dry] ' : '') . "{$count} outbound target(s) " . ($this->option('dry') ? 'would be' : 'were') . ' encrypted.');

        return self::SUCCESS;
    }
}
