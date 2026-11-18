<?php

namespace Inly\CoreWeb\Cloud;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * One content:import step, run in-process with nothing left on a queue: Cloud caches config at build, so a QUEUE_CONNECTION handed to the step's process is never read.
 */
class ImportStepCommand extends Command
{
    protected $signature = 'core-web:import-step {line : The artisan command line to run}
        {--with-search : Keep the search indexes, for a step that builds them}';

    protected $description = 'Run one content:import step without queueing (content:import runs it)';

    protected $hidden = true;

    /** A queue that drops what it is given. */
    public const DISCARD = 'core-web-discard';

    public function handle(): int
    {
        config([
            'queue.default' => 'sync',
            'queue.connections.'.self::DISCARD => ['driver' => 'null'],
            'statamic.search.queue_connection' => 'sync',
            // The trees import saves every entry again to set its order, and core-web:entry-order does the same per collection afterwards.
            'statamic.eloquent-driver.collections.update_entry_order_connection' => self::DISCARD,
            'statamic.eloquent-driver.collections.update_entry_parent_connection' => 'sync',
            // On the sync queue, a save that renders the site's cached pages again would render them all before the next row; content:import clears them at the end.
            'statamic.static_caching.background_recache' => false,
        ]);

        // Every entry the steps save would otherwise update the indexes one by one; content:import builds them once at the end where they outlive the container.
        // Statamic adds a local `cp` index when the config names none, so a null `cp` is what leaves them all alone.
        if (! $this->option('with-search')) {
            config(['statamic.search.indexes' => ['cp' => ['driver' => 'null']]]);
        }

        return Artisan::call($this->argument('line'), [], $this->output);
    }
}
