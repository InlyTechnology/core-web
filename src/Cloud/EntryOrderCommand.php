<?php

namespace Inly\CoreWeb\Cloud;

use Illuminate\Console\Command;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * content:import's last step for one collection, in its own process: a large site's whole set in one process ran out of memory or time without a word.
 */
class EntryOrderCommand extends Command
{
    protected $signature = 'core-web:entry-order {collection}';

    protected $description = 'Write one collection\'s entry URIs, order and parents (content:import runs it)';

    protected $hidden = true;

    public function handle(): int
    {
        $collection = Collection::find($this->argument('collection'));

        if ($collection === null) {
            $this->error("No collection [{$this->argument('collection')}].");

            return self::FAILURE;
        }

        // Straight to the entry repository, without the save's events and its saves of each localization again (docs/laravel-cloud.md, First deploy).
        $collection->queryEntries()->get()->each(fn ($entry) => Entry::save($entry));

        return self::SUCCESS;
    }
}
