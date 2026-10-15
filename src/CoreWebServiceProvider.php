<?php

namespace Inly\CoreWeb;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inly\CoreWeb\Chat\ChatController;
use Inly\CoreWeb\Chat\ChatSession;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Content\ContentPings;
use Inly\CoreWeb\Content\CoreEnvelope;
use Inly\CoreWeb\Content\CoreSigningKeys;
use Inly\CoreWeb\Content\VerifyCoreEnvelope;
use Inly\CoreWeb\Sitemaps\SnapshotSitemaps;
use Inly\CoreWeb\Sitemaps\SnapshotSitemapsTag;
use Inly\CoreWeb\Snapshots\PullSnapshotsCommand;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Snapshots\UncacheableWhileDegraded;
use Inly\CoreWeb\Snapshots\WarmSnapshotsCommand;
use Inly\CoreWeb\StaticCaching\SiteScopedInvalidator;
use InvalidArgumentException;
use Statamic\Statamic;
use Statamic\StaticCaching\Cacher;

/**
 * Dormant until a site switches a feature on (core-web.features): with every
 * switch off it adds no route, schedule, middleware, command, tag or cache
 * store, only the `core-web.envelope` alias a site's own routes may name.
 * The `snapshots` feature is the snapshot pipeline with its pings and
 * sitemaps; an app registers its snapshots by extending the registry:
 * `$this->app->extend(SnapshotRegistry::class, fn ($r) => $r->register(...))`.
 * The `chat` feature relays a site's visitors to Core's chat agents. The
 * `content` feature opens the envelopes Core seals with its own key for the
 * site's content endpoints, and pings Core when a page changes.
 */
class CoreWebServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/core-web.php', 'core-web');

        // Before any provider boots, once the app's config (and a test's environment) is in place; at once when registered late.
        $merge = function (): void {
            if (self::enabled('snapshots')) {
                $this->mergeSnapshotStore();
            }
        };
        $this->app->isBooted() ? $merge() : $this->app->booting($merge);

        $this->app->singleton(SnapshotRegistry::class);

        // Scoped: a read is kept for the rest of the request or job.
        $this->app->scoped(SnapshotStore::class, fn ($app) => new SnapshotStore(SnapshotStore::repository(), $app['log']));
        $this->app->scoped(SnapshotSitemaps::class);

        // Not scoped: built from config each time, so configured() answers from the configuration of the moment.
        $this->app->bind(CoreClient::class, fn () => CoreClient::fromConfig());
        $this->app->bind(CoreSigningKeys::class, fn () => CoreSigningKeys::fromConfig());
        $this->app->bind(CoreEnvelope::class, fn () => CoreEnvelope::fromConfig());
        $this->app->bind(ContentPings::class, fn () => ContentPings::fromConfig());

        $this->app->bind(SiteScopedInvalidator::class, fn ($app) => new SiteScopedInvalidator(
            $app[Cacher::class],
            $app['config']['statamic.static_caching.invalidation.rules'],
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/core-web.php' => config_path('core-web.php')], 'core-web-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'core-web-migrations');

        if (self::enabled('snapshots')) {
            $this->bootSnapshots();
        }

        if (self::enabled('chat')) {
            $this->bootChat();
        }

        // Whatever the switch: a site's own routes name the alias, and an unknown alias fails every request, refusals included.
        $this->app->make(Router::class)->aliasMiddleware(VerifyCoreEnvelope::ALIAS, VerifyCoreEnvelope::class);
    }

    /** Whether the site switched a feature on in core-web.features (true, or a string such as "1" or "true" from env). */
    public static function enabled(string $feature): bool
    {
        return filter_var(config("core-web.features.{$feature}"), FILTER_VALIDATE_BOOLEAN);
    }

    private function bootSnapshots(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/core-web.php');

        // Not console-only: a ping's refresh job calls it from a sync queue inside a web request too.
        $this->commands([WarmSnapshotsCommand::class, PullSnapshotsCommand::class]);

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

    private function bootChat(): void
    {
        $agents = self::chatAgents();

        $this->loadRoutesFrom(__DIR__.'/../routes/chat.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'core-web');

        // Per session and address; the routes' general limit already caps each address, however many sessions it mints.
        RateLimiter::for(ChatController::TURN_LIMITER, fn (Request $request) => Limit::perMinute(max(1, (int) config('core-web.chat.turn_rate_limit', 10)))
            ->by(sha1(($request->hasSession() ? $request->session()->getId() : '').'|'.$request->ip())));

        // The front end reads the hint cookie; it says only that a conversation exists, never which.
        EncryptCookies::except(array_map(ChatSession::cookieName(...), $agents));
    }

    /**
     * The agent keys in core-web.chat.agents, each a slug as Core names its agents.
     *
     * @return list<string>
     */
    public static function chatAgents(): array
    {
        $keys = array_map('strval', array_keys((array) config('core-web.chat.agents', [])));

        foreach ($keys as $key) {
            if (preg_match('~^'.ChatController::AGENT_PATTERN.'$~', $key) !== 1) {
                throw new InvalidArgumentException("core-web.chat.agents: [{$key}] is not an agent key; Core's are lowercase slugs (a-z, 0-9, single hyphens).");
            }
        }

        return $keys;
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
