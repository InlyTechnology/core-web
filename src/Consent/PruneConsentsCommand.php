<?php

namespace Inly\CoreWeb\Consent;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneConsentsCommand extends Command
{
    protected $signature = 'consent:prune';

    protected $description = 'Delete consent log rows older than core-web.consent.log.retention_days, and the banner snapshots no row names';

    public function handle(): int
    {
        $days = max(1, (int) config('core-web.consent.log.retention_days', 1825));

        $connection = DB::connection(config('core-web.consent.log.connection'));
        $log = (string) config('core-web.consent.log.table', 'core_web_consents');
        $deleted = $connection->table($log)->where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} consent rows older than {$days} days.");

        $snapshots = ConsentSnapshot::table();

        if (! $connection->getSchemaBuilder()->hasTable($snapshots)) {
            return self::SUCCESS;
        }

        // A banner is kept while any choice made on it is.
        $orphans = $connection->table($snapshots)
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotIn('fingerprint', fn ($query) => $query->select('fingerprint')->from($log)->whereNotNull('fingerprint'))
            ->delete();

        $this->info("Deleted {$orphans} banner snapshots no consent row names.");

        return self::SUCCESS;
    }
}
