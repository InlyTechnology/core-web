<?php

namespace Inly\CoreWeb;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inly\CoreWeb\Chat\ChatController;
use Inly\CoreWeb\Chat\ChatSession;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\CmsSignIn\ReconcileCmsUsersCommand;
use Inly\CoreWeb\CmsSignIn\RedirectCpLoginToCore;
use Inly\CoreWeb\CmsSignIn\SsoUserProvider;
use Inly\CoreWeb\Content\ContentPings;
use Inly\CoreWeb\Content\CoreEnvelope;
use Inly\CoreWeb\Content\CoreSigningKeys;
use Inly\CoreWeb\Content\VerifyCoreEnvelope;
use Inly\CoreWeb\ControlPanel\CoreNavLink;
use Inly\CoreWeb\Live\CoreLive;
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
 * site's content endpoints, and pings Core when a page changes. The `live`
 * feature opens the site's named live reads of Core to its pages' scripts.
 * The `cp_link` feature links the control panel to Core under Dashboard.
 * The `cms_sign_in` feature signs the control panel in from Core alone.
 */
class CoreWebServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/core-web.php', 'core-web');

        // Before any provider boots, once the app's config (and a test's environment) is in place; at once when registered late.
        $merge = function (): void {
            $this->mergeLegacyRemote();

            if (self::enabled('snapshots')) {
                $this->mergeSnapshotStore();
            }

            if (self::enabled('cms_sign_in')) {
                $this->mergeCmsSignInDefaults();
                $this->mergeSsoUserProvider();
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

    /** `pull` is the old name of `remote`: a site config still naming it fills what its `remote` leaves blank. */
    private function mergeLegacyRemote(): void
    {
        $legacy = config('core-web.pull');

        if (! is_array($legacy)) {
            return;
        }

        config(['core-web.remote' => array_replace(
            $legacy,
            array_filter((array) config('core-web.remote'), fn (mixed $value): bool => ! blank($value)),
        )]);
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

        if (self::enabled('live')) {
            self::liveReads();
            $this->loadRoutesFrom(__DIR__.'/../routes/live.php');
        }

        if (self::enabled('cp_link')) {
            CoreNavLink::register();
        }

        if (self::enabled('cms_sign_in')) {
            $this->bootCmsSignIn();
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

    private function bootCmsSignIn(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/cms-sign-in.php');
        $this->commands([ReconcileCmsUsersCommand::class]);

        Auth::provider(SsoUserProvider::DRIVER, fn () => new SsoUserProvider);

        RateLimiter::for('core-web.cms-sign-in', fn (Request $request) => Limit::perMinute(max(1, (int) config('core-web.cms_sign_in.rate_limit', 10)))
            ->by((string) $request->ip()));

        // Global, ahead of the session: a signed-in person asking for the login page is sent through Core too.
        $this->app->make(HttpKernel::class)->prependMiddleware(RedirectCpLoginToCore::class);

        if (is_string($cron = config('core-web.cms_sign_in.reconcile_schedule')) && $cron !== '') {
            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('core:reconcile-cms-users')
                ->cron($cron)
                ->withoutOverlapping());
        }
    }

    /**
     * A site's `cms_sign_in` block keeps the package's value for every key it
     * leaves out, one level into `revoke` and `core` too: a block naming only
     * its roles must not lose the reconcile schedule or Core's paths.
     */
    private function mergeCmsSignInDefaults(): void
    {
        $defaults = (require __DIR__.'/../config/core-web.php')['cms_sign_in'];
        $site = (array) config('core-web.cms_sign_in');
        $merged = array_replace($defaults, $site);

        foreach (['revoke', 'core'] as $nested) {
            $merged[$nested] = array_replace($defaults[$nested], (array) ($site[$nested] ?? []));
        }

        config(['core-web.cms_sign_in' => $merged]);
    }

    /**
     * The CP guard's provider admits only the accounts Core provisioned. Any
     * other driver would keep password sign-in open beside SSO, so it fails
     * the boot rather than leave that door quietly unlocked.
     */
    private function mergeSsoUserProvider(): void
    {
        $config = $this->app['config'];
        $guard = (string) $config->get('statamic.users.guards.cp', 'web');
        $provider = (string) $config->get("auth.guards.{$guard}.provider", 'users');
        $driver = $config->get("auth.providers.{$provider}.driver");

        if ($driver === 'statamic') {
            $config->set("auth.providers.{$provider}.driver", SsoUserProvider::DRIVER);

            return;
        }

        if ($driver !== SsoUserProvider::DRIVER) {
            throw new InvalidArgumentException("CMS sign-in needs the CP guard's user provider [{$provider}] on Statamic's driver, not [{$driver}].");
        }
    }

    private function bootChat(): void
    {
        $agents = self::chatAgents();

        $this->loadRoutesFrom(__DIR__.'/../routes/chat.php');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'core-web');

        // Per session and address, and per address alone: a fresh session per message would otherwise reset the first.
        RateLimiter::for(ChatController::TURN_LIMITER, fn (Request $request) => [
            Limit::perMinute(max(1, (int) config('core-web.chat.turn_rate_limit', 10)))
                ->by('session|'.sha1(($request->hasSession() ? $request->session()->getId() : '').'|'.$request->ip())),
            Limit::perMinute(max(1, (int) config('core-web.chat.turn_rate_limit_per_address', 30)))
                ->by('address|'.sha1((string) $request->ip())),
        ]);

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

    /**
     * The read names in core-web.live.reads, each a slug, as the relay's URL carries it.
     *
     * @return list<string>
     */
    public static function liveReads(): array
    {
        $names = array_map('strval', array_keys((array) config('core-web.live.reads', [])));

        foreach ($names as $name) {
            if (preg_match('~^'.CoreLive::NAME_PATTERN.'$~', $name) !== 1) {
                throw new InvalidArgumentException("core-web.live.reads: [{$name}] is not a read name; use a lowercase slug (a-z, 0-9, single hyphens).");
            }
        }

        return $names;
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
