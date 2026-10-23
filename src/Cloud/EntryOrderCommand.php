<?php

namespace Inly\CoreWeb\Cloud;

use Illuminate\Console\Command;
use Statamic\Facades\Collection;

/**
 * content:import's last step for one collection, in its own process: a large site's whole set in one process ran out of memory or time without a word.
 */
class EntryOrderCommand extends Command
{
    protected $signature = 'core-web:entry-order {collection}';

    protected $description = 'Recompute one collection\'s entry URIs and order (content:import runs it)';

    protected $hidden = true;

    public function handle(): int
    {
        $collection = Collection::find($this->argument('collection'));

        if ($collection === null) {
            $this->error("No collection [{$this->argument('collection')}].");

            return self::FAILURE;
        }

        // The driver hands entry ordering to a queued job; here it runs in-process, whatever queue the environment (or a cached config) names.
        config(['queue.default' => 'sync', 'statamic.eloquent-driver.collections.update_entry_order_connection' => 'sync']);

        $collection->updateEntryUris();
        $collection->updateEntryOrder();

        return self::SUCCESS;
    }
}
