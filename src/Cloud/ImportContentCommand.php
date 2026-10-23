<?php

namespace Inly\CoreWeb\Cloud;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Inly\CoreWeb\CoreWebServiceProvider;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;

/**
 * Loads content/ into an empty database for an environment on the eloquent content driver; why the order and the separate processes matter is in docs/laravel-cloud.md.
 */
class ImportContentCommand extends Command
{
    protected $signature = 'content:import
        {--fresh : Empty the content tables first (form submissions, revisions and tokens are kept)}
        {--force : Skip the --fresh confirmation}
        {--skip-asset-meta : Leave asset meta alone; images resolve to nothing until core-web:asset-meta has run for each container}';

    protected $description = 'Load content/ into an empty database (eloquent content driver)';

    /** The tables of everything CloudDefaults::CONTENT moves to the database. */
    private const TABLES = [
        'entries', 'trees', 'global_set_variables', 'taxonomy_terms',
        'assets_meta', 'form_submissions', 'revisions', 'tokens',
    ];

    /** Schema::getColumns() type names a Statamic id fits in, on Postgres, MySQL and SQLite. */
    private const STRING_TYPES = ['varchar', 'character varying', 'text', 'bpchar', 'char', 'character', 'nvarchar', 'longtext', 'mediumtext', 'tinytext'];

    /** @var array<string, array<string, 'string'|'json'>> */
    private const COLUMNS = [
        'entries' => ['id' => 'string', 'origin_id' => 'string', 'data' => 'json'],
        'trees' => ['tree' => 'json', 'settings' => 'json'],
        'global_set_variables' => ['data' => 'json'],
        'taxonomy_terms' => ['data' => 'json'],
        'assets_meta' => ['meta' => 'json'],
        'form_submissions' => ['data' => 'json'],
        'revisions' => ['attributes' => 'json'],
        'tokens' => ['data' => 'json'],
    ];

    /** What --fresh empties: content/ holds all of it again. Submissions, revisions and tokens exist only in the database. */
    private const FRESH_TABLES = [
        'entries', 'trees', 'global_set_variables', 'taxonomy_terms', 'assets_meta',
    ];

    public function handle(): int
    {
        if (config('statamic.eloquent-driver.entries.driver') !== 'eloquent') {
            $this->error('Entries are not on the eloquent driver: this environment already reads content/.');

            return self::FAILURE;
        }

        $db = $this->connection();
        $prefix = (string) config('statamic.eloquent-driver.table_prefix');

        $missing = array_values(array_filter(self::TABLES, fn (string $table): bool => ! $db->getSchemaBuilder()->hasTable($prefix.$table)));

        if ($missing !== []) {
            $this->error('Missing tables: '.implode(', ', $missing).'. Publish core-web\'s copies of the eloquent driver\'s migrations (--tag=core-web-cloud-migrations) and run migrate first.');

            return self::FAILURE;
        }

        $wrong = self::wrongColumns(fn (string $table): array => $db->getSchemaBuilder()->getColumns($prefix.$table));

        if ($wrong !== []) {
            $this->error('Columns the import cannot fill as content/ has it: '.implode(', ', $wrong).'. Entry ids are strings (the home page\'s is `home`) and content is json, not jsonb, which re-sorts keys. Publish core-web\'s copies of the driver\'s migrations over the driver\'s (--tag=core-web-cloud-migrations --force) and rebuild these tables: they are create migrations.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            if (! $this->option('force') && ! $this->confirm('Replace every entry, tree, global, term and asset meta row with content/? Editors\' work since the last import is lost.')) {
                $this->error('Nothing changed. Pass --force to run --fresh without the question.');

                return self::FAILURE;
            }

            foreach (self::FRESH_TABLES as $table) {
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
            'form submissions' => ['statamic:eloquent:import-forms', '--only-form-submissions', '--force'],
        ];

        foreach ($steps as $label => $command) {
            if (! $this->step($label, $command)) {
                return self::FAILURE;
            }
        }

        if (! $this->option('skip-asset-meta')) {
            $containers = AssetContainer::all()->map->handle()->values()->all();

            foreach ($containers as $i => $handle) {
                if (! $this->step("asset meta: {$handle}", ['core-web:asset-meta', $handle], timeout: 3600)) {
                    $this->line('The other rows are in. To finish, run `php artisan core-web:asset-meta <container>` for '.implode(', ', array_slice($containers, $i)).' (a run again skips what is already in), then `php artisan core-web:entry-order <collection>` for '.Collection::handles()->implode(', ').', then statamic:static:clear and cache:clear.');

                    return self::FAILURE;
                }
            }
        }

        $handles = Collection::handles()->values()->all();

        foreach ($handles as $i => $handle) {
            if (! $this->step("entry URIs and order: {$handle}", ['core-web:entry-order', $handle], timeout: 3600)) {
                $this->line('The rows are in. To finish, run `php artisan core-web:entry-order <collection>` for '.implode(', ', array_slice($handles, $i)).', then statamic:static:clear and cache:clear.');

                return self::FAILURE;
            }
        }

        $this->importUsers();
        $this->updateSearchIndexes();

        // Rows were written without events: nothing invalidated the pages that show them.
        $this->callSilently('statamic:static:clear');
        $this->callSilently('cache:clear');

        foreach (self::TABLES as $table) {
            $this->line(sprintf('  %-22s %d', $table, $db->table($prefix.$table)->count()));
        }

        return self::SUCCESS;
    }

    /**
     * The content columns whose type would break the import or reorder content: an entry id that is not a string (the driver's stock migrations make uuid or bigint), or jsonb. As `table.column (type)`.
     *
     * @param  callable(string): list<array{name: string, type_name: string}>  $columns  a table's columns, as Schema::getColumns() gives them
     * @return list<string>
     */
    public static function wrongColumns(callable $columns): array
    {
        $wrong = [];

        foreach (self::COLUMNS as $table => $expected) {
            foreach ($columns($table) as $column) {
                $type = strtolower((string) $column['type_name']);
                $want = $expected[$column['name']] ?? null;

                if (($want === 'string' && ! in_array($type, self::STRING_TYPES, true)) || ($want === 'json' && $type === 'jsonb')) {
                    $wrong[] = "{$table}.{$column['name']} ({$type})";
                }
            }
        }

        return $wrong;
    }

    /**
     * Why users/ is not imported: '' when there is nothing to say (users stay files, or the site has none), null when it is imported.
     */
    public function usersSkipReason(): ?string
    {
        if (config('statamic.users.repository') !== 'eloquent') {
            return '';
        }

        $directory = (string) config('statamic.stache.stores.users.directory', base_path('users'));

        if ((glob(rtrim($directory, '/').'/*.yaml') ?: []) === []) {
            return '';
        }

        // Core makes each account at its first sign-in, and refuses to adopt one it did not make.
        if (CoreWebServiceProvider::enabled('cms_sign_in')) {
            return 'Core sign-in is on: each account is made at its first sign-in, so users/ was not imported.';
        }

        $users = $this->usersTable();

        if ($users === null) {
            return 'the users table does not exist yet, so users/ was not imported. Run migrate, then statamic:eloquent:import-users.';
        }

        return $users->exists() ? 'the users table already holds accounts, so users/ was not imported.' : null;
    }

    /** Last and never fatal: content:import refuses to run again once entries are in, so a users failure must not stop it short of done. */
    private function importUsers(): void
    {
        $reason = $this->usersSkipReason();

        if ($reason !== null) {
            if ($reason !== '') {
                $this->line('  users: '.$reason);
            }

            return;
        }

        // The command exits 0 when it imported nothing (a model without HasUuids, say), so the table is what tells.
        if (! $this->step('users', ['statamic:eloquent:import-users'], quiet: false) || ! $this->usersTable()?->exists()) {
            $this->warn('  users/ was not imported. Fix what the output above names, then run php artisan statamic:eloquent:import-users.');
        }
    }

    /**
     * The search indexes whose driver outlives the container; when there is one, the import rebuilds every index once at the end. A `local` index is a file on one container's disk and builds itself on its first search.
     *
     * @return list<string>
     */
    public static function lastingSearchIndexes(): array
    {
        return collect(config('statamic.search.indexes', []))
            ->reject(fn (array $index): bool => in_array($index['driver'] ?? 'local', ['local', 'null'], true))
            ->keys()
            ->all();
    }

    /** Never fatal, like the users import: the content is in. */
    private function updateSearchIndexes(): void
    {
        if (self::lastingSearchIndexes() === []) {
            return;
        }

        if (! $this->step('search indexes', ['statamic:search:update', '--all'], timeout: 3600, search: true)) {
            $this->warn('  The search indexes were not rebuilt. Fix what the output above names, then run php artisan statamic:search:update --all.');
        }
    }

    /** The table the CP guard's user model writes to, which is where the import puts the rows. */
    private function usersTable(): ?Builder
    {
        $guard = config('statamic.users.guards.cp', 'web');
        $class = config('auth.providers.'.config("auth.guards.{$guard}.provider").'.model');

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        $model = new $class;

        return $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())
            ? $model->getConnection()->table($model->getTable())
            : null;
    }

    /**
     * The process that runs one step, through core-web:import-step so it queues nothing.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    public static function stepProcess(array $command, bool $search = false): array
    {
        return [
            PHP_BINARY, base_path('artisan'), 'core-web:import-step', implode(' ', array_map(
                fn (string $part): string => preg_match('/^[\w:=.-]+$/', $part) === 1 ? $part : escapeshellarg($part),
                [...$command, '--no-interaction'],
            )),
            ...($search ? ['--with-search'] : []), '--no-interaction',
        ];
    }

    private function step(string $label, array $command, int $timeout = 600, bool $quiet = true, bool $search = false): bool
    {
        $result = null;
        $this->components->task($label, function () use ($command, $timeout, $search, &$result) {
            try {
                $result = Process::timeout($timeout)->run(self::stepProcess($command, $search));
            } catch (ProcessTimedOutException $e) {
                $result = $e->result;
            }

            return $result->successful();
        });

        if (! $quiet && $result->successful()) {
            $this->line(trim($result->output()));
        }

        // Both streams: the import commands draw their progress bars on stderr, so stderr alone
        // can be nothing but a bar while the exception went to stdout.
        if ($result->failed()) {
            $this->error(sprintf('%s %s.', $command[0], $result->exitCode() === null ? 'ran out of time' : 'exited with code '.$result->exitCode()));
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
