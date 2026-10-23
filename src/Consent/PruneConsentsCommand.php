<?php

namespace Inly\CoreWeb\Consent;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneConsentsCommand extends Command
{
    protected $signature = 'consent:prune';

    protected $description = 'Delete consent log rows older than core-web.consent.log.retention_days';

    public function handle(): int
    {
        $days = max(1, (int) config('core-web.consent.log.retention_days', 1825));

        $deleted = DB::connection(config('core-web.consent.log.connection'))
            ->table((string) config('core-web.consent.log.table', 'core_web_consents'))
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} consent rows older than {$days} days.");

        return self::SUCCESS;
    }
}
