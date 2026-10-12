<?php

namespace Inly\CoreWeb;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Sitemaps\SnapshotSitemaps;
use Inly\CoreWeb\Sitemaps\SnapshotSitemapsTag;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Snapshots\UncacheableWhileDegraded;
use Inly\CoreWeb\Snapshots\WarmSnapshotsCommand;
use Inly\CoreWeb\StaticCaching\SiteScopedInvalidator;
use InvalidArgumentException;
use Statamic\Statamic;
use Statamic\StaticCaching\Cacher;

/**
 * The snapshot store and registry, the Core client, the ping endpoint and the
 * snapshot sitemaps. An app registers its snapshots by extending the registry:
 * `$this->app->extend(SnapshotRegistry::class, fn ($r) => $r->register(...))`.
 */
class CoreWebServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/core-web.php', 'core-web');
        $this->mergeSnapshotStore();

        $this->app->singleton(SnapshotRegistry::class);

        // Scoped: a read is kept for the rest of the request or job.
        $this->app->scoped(SnapshotStore::class, fn ($app) => new SnapshotStore(SnapshotStore::repository(), $app['log']));
        $this->app->scoped(SnapshotSitemaps::class);

        // Not scoped: built from config each time, so configured() answers from the configuration of the moment.
        $this->app->bind(CoreClient::class, fn () => CoreClient::fromConfig());

        $this->app->bind(SiteScopedInvalidator::class, fn ($app) => new SiteScopedInvalidator(
            $app[Cacher::class],
            $app['config']['statamic.static_caching.invalidation.rules'],
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/core-web.php' => config_path('core-web.php')], 'core-web-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'core-web-migrations');

        $this->loadRoutesFrom(__DIR__.'/../routes/core-web.php');

        // Not console-only: a ping's refresh job calls it from a sync queue inside a web request too.
        $this->commands([WarmSnapshotsCommand::class]);

        SnapshotSitemapsTag::register();

        if (config('core-web.snapshots.uncacheable_while_degraded', true)) {
            // After Statamic's static cache middleware (Statamic builds the group once the app has booted, then runs
            // these callbacks), so the header is set before it decides, whichever provider boots first.
            Statamic::booted(fn () => $this->app->make(Router::class)->pushMiddlewareToGroup('statamic.web', UncacheableWhileDegraded::class));
        }

        if (is_string($cron = config('core-web.snapshots.warm_schedule')) && $cron !== '') {
            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('core:warm')
                ->cron($cron)
                ->withoutOverlapping()
                ->runInBackground());
        }
    }

    /** The `snapshots` store (a file or a database store, core-web.snapshots.store_driver), unless the app defines a store by that name itself. */
    private function mergeSnapshotStore(): void
    {
        $config = $this->app['config'];

        if ($config->get('core-web.snapshots.store') !== 'snapshots' || $config->has('cache.stores.snapshots')) {
            return;
        }

        $driver = $config->get('core-web.snapshots.store_driver', 'file');

        $config->set('cache.stores.snapshots', match ($driver) {
            'file' => [
                'driver' => 'file',
                'path' => storage_path('framework/snapshots'),
                'lock_path' => storage_path('framework/snapshots'),
            ],
            'database' => [
                'driver' => 'database',
                'connection' => $config->get('core-web.snapshots.database.connection'),
                'table' => $config->get('core-web.snapshots.database.table', 'core_snapshots_cache'),
                'lock_connection' => $config->get('core-web.snapshots.database.connection'),
                'lock_table' => $config->get('core-web.snapshots.database.lock_table', 'core_snapshots_cache_locks'),
            ],
            default => throw new InvalidArgumentException("core-web.snapshots.store_driver must be file or database, not [{$driver}]."),
        });
    }
}
