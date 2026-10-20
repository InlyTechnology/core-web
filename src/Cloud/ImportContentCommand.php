<?php

namespace Inly\CoreWeb\Cloud;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Statamic\Facades\Collection;

/**
 * Loads content/ into an EMPTY database, for an environment whose content runs on Statamic's
 * eloquent driver (Laravel Cloud, with features.cloud): its first deploy, a local database to
 * test against, or a rebuild after a disaster.
 *
 * Never over a database editors use. It writes rows directly, so every row is overwritten,
 * no events fire (a statically cached page keeps serving the old text) and no revision is
 * recorded. It refuses while entries exist, unless --fresh empties the content tables first.
 *
 * The order is the point. The driver's own import-collections saves the trees before the
 * entries exist, and saving a tree drops every id the entries table doesn't hold yet: the
 * trees come out empty and every page gets the wrong URI. So entries first, then collection
 * trees, then the rest, and the URIs and order recomputed last.
 *
 * Each import runs in its own process. In one process the entry import leaves the (still
 * empty) database trees cached, and the tree import then saves those instead of the files:
 * every structured page lost its parent and home its root.
 */
class ImportContentCommand extends Command
{
    protected $signature = 'content:import
        {--fresh : Empty the content tables first}
        {--skip-asset-meta : Leave asset meta alone; images resolve to nothing until statamic:eloquent:import-assets --only-assets has run}';

    protected $description = 'Load content/ into an empty database (eloquent content driver)';

    /** The tables of everything CloudDefaults::CONTENT moves to the database. */
    private const TABLES = [
        'entries', 'trees', 'global_set_variables', 'taxonomy_terms',
        'assets_meta', 'form_submissions', 'revisions', 'tokens',
    ];

    public function handle(): int
    {
        if (config('statamic.eloquent-driver.entries.driver') !== 'eloquent') {
            $this->error('Entries are not on the eloquent driver: this environment already reads content/.');

            return self::FAILURE;
        }

        $db = $this->connection();
        $prefix = (string) config('statamic.eloquent-driver.table_prefix');

        if ($this->option('fresh')) {
            foreach (self::TABLES as $table) {
                $db->table($prefix.$table)->delete();
            }
        } elseif ($db->table($prefix.'entries')->exists()) {
            $this->error('The database already holds entries. --fresh replaces them: never on a database editors use.');

            return self::FAILURE;
        }

        $steps = [
            'entries' => ['statamic:eloquent:import-entries'],
            'collection trees' => ['statamic:eloquent:import-collections', '--only-collection-trees', '--force'],
            'nav trees' => ['statamic:eloquent:import-navs', '--only-nav-trees', '--force'],
            'global variables' => ['statamic:eloquent:import-globals', '--only-global-variables', '--force'],
            'terms' => ['statamic:eloquent:import-taxonomies', '--only-terms', '--force'],
            'asset meta' => ['statamic:eloquent:import-assets', '--only-assets', '--force'],
            'form submissions' => ['statamic:eloquent:import-forms', '--only-form-submissions', '--force'],
        ];

        if ($this->option('skip-asset-meta')) {
            unset($steps['asset meta']);
        }

        foreach ($steps as $label => $command) {
            if (! $this->step($label, $command)) {
                return self::FAILURE;
            }
        }

        // The driver hands entry ordering to a queued job; here it has to be done before the
        // command returns, whatever QUEUE_CONNECTION the environment runs.
        config(['queue.default' => 'sync']);

        $this->components->task('entry URIs and order', function () {
            foreach (Collection::all() as $collection) {
                $collection->updateEntryUris();
                $collection->updateEntryOrder();
            }
        });

        // Rows were written without events: nothing invalidated the pages that show them.
        $this->callSilently('statamic:static:clear');
        $this->callSilently('cache:clear');

        foreach (self::TABLES as $table) {
            $this->line(sprintf('  %-22s %d', $table, $db->table($prefix.$table)->count()));
        }

        return self::SUCCESS;
    }

    private function step(string $label, array $command): bool
    {
        $result = null;
        $this->components->task($label, function () use ($command, &$result) {
            $result = Process::env(['QUEUE_CONNECTION' => 'sync'])->timeout(600)->run([PHP_BINARY, base_path('artisan'), ...$command, '--no-interaction']);

            return $result->successful();
        });

        // Both streams: the import commands draw their progress bars on stderr, so stderr alone
        // can be nothing but a bar while the exception went to stdout.
        if ($result->failed()) {
            $this->error(sprintf('%s exited with code %d.', $command[0], $result->exitCode()));
            $this->line(trim($result->output()));
            $this->line(trim($result->errorOutput()));
        }

        return $result->successful();
    }

    private function connection(): Connection
    {
        return DB::connection(config('statamic.eloquent-driver.connection') ?: null);
    }
}
