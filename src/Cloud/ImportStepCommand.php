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

    public function handle(): int
    {
        config([
            'queue.default' => 'sync',
            'statamic.eloquent-driver.collections.update_entry_order_connection' => 'sync',
        ]);

        // Every entry the steps save would otherwise update the indexes one by one; content:import builds them once at the end where they outlive the container.
        if (! $this->option('with-search')) {
            config(['statamic.search.indexes' => []]);
        }

        return Artisan::call($this->argument('line'), [], $this->output);
    }
}
