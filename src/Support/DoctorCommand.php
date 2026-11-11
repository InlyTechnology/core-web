<?php

namespace Inly\CoreWeb\Support;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Inly\CoreWeb\Cloud\CloudDefaults;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\StaticCaching\DefaultStrategy;
use Inly\CoreWeb\StaticCaching\SiteScopedInvalidator;
use Inly\CoreWeb\StaticCaching\StaticCacheDefaults;
use Statamic\StaticCaching\Invalidator;

/**
 * What the switched-on features need from this environment that a site's own setup can get wrong: the Core
 * client, a real queue, the scheduler, how a save reaches the static cache. Runs offline, so a deploy or a launch
 * check can call it anywhere.
 */
class DoctorCommand extends Command
{
    /** Features that call Core with the site's own client. */
    public const CLIENT_FEATURES = ['snapshots', 'chat', 'content', 'live', 'forms', 'cms_sign_in', 'preview'];

    /** Features whose work a ping or Core's notice queues: on a sync queue it runs inside Core's request. */
    public const QUEUE_FEATURES = ['snapshots', 'cms_sign_in'];

    /** The commands each feature schedules, by the config key that sets when. */
    public const SCHEDULED = [
        'snapshots' => ['core:warm', 'snapshots.warm_schedule'],
        'checks' => ['content:check', 'checks.schedule'],
        'cms_sign_in' => ['core:reconcile-cms-users', 'cms_sign_in.reconcile_schedule'],
    ];

    protected $signature = 'core-web:doctor';

    protected $description = 'Check what the switched-on core-web features need from this environment';

    private bool $anyFailed = false;

    public function handle(Schedule $schedule): int
    {
        $features = array_keys(array_filter(
            (array) config('core-web.features'),
            fn (mixed $on, string $feature): bool => CoreWebServiceProvider::enabled($feature),
            ARRAY_FILTER_USE_BOTH,
        ));
        $this->line('Features on: '.implode(', ', $features));

        $this->checkClient($features);
        $this->checkQueue($features);
        $this->checkSchedule($features, $schedule);
        $this->checkPingSecret($features);
        $this->checkCmsAccounts($features);
        $this->checkProposals($features);
        $this->checkStaticCache();

        if (in_array('media', $features, true)) {
            $this->line('media: run `php artisan media:check` for the disks and the bucket.');
        }

        return $this->anyFailed ? self::FAILURE : self::SUCCESS;
    }

    /** @param  list<string>  $features */
    private function checkClient(array $features): void
    {
        $needing = array_values(array_intersect($features, self::CLIENT_FEATURES));

        if ($needing === []) {
            return;
        }

        $url = (string) config('core-web.client.base_url');

        if ($this->laravel->environment('local') && $url !== '' && ! self::isLocalHost($url)) {
            $this->failure("Core client: CORE_URL points this local checkout at {$url}. Remove it: production data, the CP sign-in and forms come through the live site (content:login); CORE_URL locally names a local backoffice only");

            return;
        }

        $missing = array_keys(array_filter([
            'core.url (or CORE_URL)' => config('core-web.client.base_url'),
            'core.client_id (or CORE_CLIENT_ID)' => config('core-web.client.client_id'),
            'CORE_CLIENT_SECRET' => config('core-web.client.client_secret'),
        ], fn (mixed $value): bool => blank($value)));

        if ($missing === []) {
            $this->ok('Core client: '.config('core-web.client.base_url'));

            return;
        }

        // A local machine may run without a Core: its data comes from core:pull and content:pull.
        if ($this->laravel->environment('local') && blank(config('core-web.client.base_url'))) {
            $this->warning('Core client: no CORE_URL here; production data, the CP sign-in and forms come through the live site (content:login, content:pull, core:pull)');

            return;
        }

        $this->failure('Core client: '.implode(', ', $missing).' not set, which '.implode(', ', $needing).' need');
    }

    /** @param  list<string>  $features */
    private function checkQueue(array $features): void
    {
        $needing = array_values(array_intersect($features, self::QUEUE_FEATURES));

        if ($needing === [] || $this->laravel->environment(['local', 'testing'])) {
            return;
        }

        if (config('queue.default') === 'sync') {
            $message = 'Queue: QUEUE_CONNECTION is sync, so '.implode(', ', $needing).' run their jobs inside Core\'s request and a bulk ping is cut off; use a real queue with a worker';
            // A staging nothing pings may run sync; production always receives Core's pings and notices.
            $this->laravel->environment('production') ? $this->failure($message) : $this->warning($message.' wherever Core pings this server');

            return;
        }

        $this->ok('Queue: '.config('queue.default').' (needs a running worker)');
    }

    /** @param  list<string>  $features */
    private function checkSchedule(array $features, Schedule $schedule): void
    {
        $scheduled = collect($schedule->events())
            ->map(fn (Event $event): string => (string) $event->command)
            ->all();

        foreach (self::SCHEDULED as $feature => [$command, $key]) {
            if (! in_array($feature, $features, true)) {
                continue;
            }

            $found = collect($scheduled)->contains(fn (string $line): bool => str_contains($line, $command));
            $found
                ? $this->ok("Schedule: {$command} (needs `schedule:run` every minute on every server)")
                : $this->warning("Schedule: {$command} is not scheduled (core-web.{$key} is null); schedule it in the site");
        }
    }

    /** @param  list<string>  $features */
    private function checkPingSecret(array $features): void
    {
        if (in_array('snapshots', $features, true) && ! blank(config('core-web.ping.secret'))) {
            $this->warning('Pings: WEBSITE_SYNC_SECRET is set; blank it once Core seals every ping with its own key');
        }
    }

    /** @param  list<string>  $features */
    private function checkCmsAccounts(array $features): void
    {
        if (! in_array('cms_sign_in', $features, true) || ! config('statamic.git.enabled')) {
            return;
        }

        $users = rtrim(str_replace('\\', '/', (string) config('statamic.stache.stores.users.directory', base_path('users'))), '/');
        $committed = collect((array) config('statamic.git.paths'))
            ->map(fn (mixed $path): string => rtrim(str_replace('\\', '/', (string) $path), '/'))
            ->contains(fn (string $path): bool => $path === $users || str_starts_with($users.'/', $path.'/'));

        $committed
            ? $this->failure('CMS accounts: statamic.git.paths commits the users folder; accounts live on the server that created them')
            : $this->ok('CMS accounts: not committed by Statamic\'s git integration');
    }

    /** @param  list<string>  $features */
    private function checkProposals(array $features): void
    {
        if (! in_array('drafts', $features, true)) {
            return;
        }

        $disk = (string) config('core-web.drafts.proposals.disk');
        [$holder, $held] = $disk === '' ? ['', null] : $this->diskHoldingFiles($disk);

        // Every CP page counts the open proposals, so a disk that is not defined fails them all.
        if ($disk !== '' && $held === null) {
            $missing = $holder === $disk ? "{$disk}," : "{$disk}, scoped to {$holder},";
            $this->failure("Proposals: core-web.drafts.proposals.disk names {$missing} which is not a defined disk");

            return;
        }

        if (! in_array('cloud', $features, true) || ! CloudDefaults::onCloud()) {
            return;
        }

        $unset = 'leave core-web.drafts.proposals.disk unset so the private bucket Cloud attaches is used';

        if ($disk === '') {
            $this->failure('Proposals: kept on the default disk, the public bucket on Cloud; attach the private bucket as core-web.cloud.uploads_disk');

            return;
        }

        if (($held['driver'] ?? null) === 'local') {
            $this->failure("Proposals: the {$disk} disk is local, so staged changes are lost on the next deploy; {$unset}");

            return;
        }

        $bucket = $held['bucket'] ?? null;
        $private = $this->diskHoldingFiles((string) config('core-web.cloud.uploads_disk', 'private'))[1]['bucket'] ?? null;

        if (filled($bucket) && $bucket === $private) {
            $this->ok("Proposals: kept on the {$disk} disk, in the private bucket");

            return;
        }

        $public = collect([config('filesystems.default'), config('core-web.media.bucket.cloud_disk') ?: 'media', ...array_keys((array) config('core-web.media.disks'))])
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->map(fn (string $name): array => $this->diskHoldingFiles($name));
        $publicBuckets = $public->map(fn (array $public) => $public[1]['bucket'] ?? null)->filter();

        $public->map(fn (array $public): string => $public[0])->contains($holder) || (filled($bucket) && $publicBuckets->contains($bucket))
            ? $this->failure("Proposals: the {$disk} disk is on the public bucket, where anyone could read a staged change by its path; {$unset}")
            : $this->warning("Proposals: kept on the {$disk} disk, which is not the private bucket Cloud attaches; make sure its bucket is private");
    }

    /**
     * The disk that holds a disk's files, following a scoped disk to the disk it is scoped to: its name and its
     * config, null when it is not defined. A parent given inline has no name, so the scoped disk's name is kept.
     *
     * @return array{0: string, 1: array<string, mixed>|null}
     */
    private function diskHoldingFiles(string $disk): array
    {
        $config = config("filesystems.disks.{$disk}");

        for ($hops = 0; $hops < 5 && is_array($config) && ($config['driver'] ?? null) === 'scoped'; $hops++) {
            $parent = $config['disk'] ?? null;

            if (is_array($parent)) {
                $config = $parent;

                continue;
            }

            if (! is_string($parent) || $parent === '') {
                break;
            }

            $disk = $parent;
            $config = config("filesystems.disks.{$parent}");
        }

        return [$disk, is_array($config) ? $config : null];
    }

    /** How a save reaches the static cache here, by docs/static-cache.md's defaults or the site's own choice. */
    private function checkStaticCache(): void
    {
        $config = $this->laravel['config'];

        if (blank($config->get('statamic.static_caching.strategy'))) {
            if (! $this->laravel->environment(DefaultStrategy::UNCACHED_ENVIRONMENTS)) {
                $this->warning('Static cache: off, as STATAMIC_STATIC_CACHING_STRATEGY or the site\'s config chose');
            }

            return;
        }

        $connection = StaticCacheDefaults::workerConnection($config);

        if (! $config->get('statamic.static_caching.background_recache')) {
            $this->ok('Static cache: a save forgets its pages, and each one\'s next visitor renders it');
        } elseif ($connection === '') {
            $warm = StaticCacheDefaults::warmConnection($config);
            $this->warning($warm === ''
                ? 'Static cache: background_recache is on, but neither warm_queue_connection nor queue.default names a connection to put the renders on'
                : "Static cache: background_recache is on, but the {$warm} failover connection names no connection to put the renders on");
        } elseif (StaticCacheDefaults::warmsOnAWorker($config)) {
            $queue = StaticCacheDefaults::warmQueue($config);
            $this->ok("Static cache: a save renders its pages again on the {$connection} connection's {$queue} queue (needs a worker on that queue)");
        } else {
            $driver = $config->get("queue.connections.{$connection}.driver") ?? 'not defined';
            $this->warning("Static cache: background_recache is on, but the {$connection} connection ({$driver}) runs its jobs without a worker: in the request that saves, after it, or never");
        }

        if ($config->get('statamic.static_caching.invalidation.rules') !== 'all') {
            return;
        }

        $class = (string) $config->get('statamic.static_caching.invalidation.class');

        if (is_a($class, SiteScopedInvalidator::class, true)) {
            $this->ok('Static cache: SiteScopedInvalidator keeps an entry, global or tree save to its own site\'s pages');
        } elseif ($class === '') {
            $this->warning('Static cache: statamic.static_caching.invalidation.class names no class, so Statamic\'s own `all` rule applies: a save clears every site\'s pages, or renders again only those cached on the static cache\'s base URL (docs/static-cache.md)');
        } elseif (class_exists($class) && is_a($class, Invalidator::class, true)) {
            $this->ok("Static cache: a save is invalidated by {$class}, the site's own choice");
        } else {
            $this->warning("Static cache: statamic.static_caching.invalidation.class names {$class}, which is no Statamic invalidator class");
        }
    }

    private function ok(string $message): void
    {
        $this->line("<info>ok</info>   {$message}");
    }

    private function warning(string $message): void
    {
        $this->line("<comment>warn</comment> {$message}");
    }

    private function failure(string $message): void
    {
        $this->anyFailed = true;
        $this->line("<error>fail</error> {$message}");
    }

    /** A Core on this machine or its network (Herd, DDEV, Lando, Docker, a LAN address), the only kind a local checkout may name. */
    private static function isLocalHost(string $url): bool
    {
        $host = rtrim(trim(strtolower((string) parse_url($url, PHP_URL_HOST)), '[]'), '.');

        if (in_array($host, ['localhost', 'host.docker.internal'], true)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        foreach (['.test', '.localhost', '.local', '.ddev.site', '.lndo.site'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
