<?php

namespace Inly\CoreWeb;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inly\CoreWeb\Chat\ChatController;
use Inly\CoreWeb\Chat\ChatSession;
use Inly\CoreWeb\Checks\ContentCheckCommand;
use Inly\CoreWeb\Checks\ContentChecks;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Client\SiteCore;
use Inly\CoreWeb\Cloud\CloudDefaults;
use Inly\CoreWeb\Cloud\DeployCommand;
use Inly\CoreWeb\Cloud\ImportContentCommand;
use Inly\CoreWeb\Cloud\ScheduledEntriesCommand;
use Inly\CoreWeb\CmsSignIn\ReconcileCmsUsersCommand;
use Inly\CoreWeb\CmsSignIn\RedirectCpLoginToCore;
use Inly\CoreWeb\CmsSignIn\SsoUserProvider;
use Inly\CoreWeb\Consent\Consent;
use Inly\CoreWeb\Consent\PruneConsentsCommand;
use Inly\CoreWeb\Content\ContentPings;
use Inly\CoreWeb\Content\CoreEnvelope;
use Inly\CoreWeb\Content\CoreSigningKeys;
use Inly\CoreWeb\Content\CoreUsers;
use Inly\CoreWeb\Content\VerifyCoreEnvelope;
use Inly\CoreWeb\ContentSync\AuthenticateSyncToken;
use Inly\CoreWeb\ContentSync\ContentLoginCommand;
use Inly\CoreWeb\ContentSync\ContentLogoutCommand;
use Inly\CoreWeb\ContentSync\ContentPullCommand;
use Inly\CoreWeb\ContentSync\ContentPushCommand;
use Inly\CoreWeb\ContentSync\DeviceAuthorizations;
use Inly\CoreWeb\ContentSync\GuardSyncRequests;
use Inly\CoreWeb\ContentSync\LocalContent;
use Inly\CoreWeb\ContentSync\SyncLimits;
use Inly\CoreWeb\ContentSync\SyncTokens;
use Inly\CoreWeb\ControlPanel\CoreNavLink;
use Inly\CoreWeb\Drafts\DraftBase;
use Inly\CoreWeb\Drafts\DraftValueNormaliser;
use Inly\CoreWeb\Drafts\FieldsHash;
use Inly\CoreWeb\Drafts\KeepDraftProvenance;
use Inly\CoreWeb\Drafts\StoredValues;
use Inly\CoreWeb\Forms\CoreForms;
use Inly\CoreWeb\Live\CoreLive;
use Inly\CoreWeb\Mail\MailSender;
use Inly\CoreWeb\Media\CheckMediaCommand;
use Inly\CoreWeb\Media\CpThumbnails;
use Inly\CoreWeb\Media\MediaDisks;
use Inly\CoreWeb\Media\SyncMediaCommand;
use Inly\CoreWeb\Media\UrlScheme;
use Inly\CoreWeb\Proposals\Proposals;
use Inly\CoreWeb\Proposals\ProposalStore;
use Inly\CoreWeb\Sitemaps\SnapshotSitemaps;
use Inly\CoreWeb\Sitemaps\SnapshotSitemapsTag;
use Inly\CoreWeb\Snapshots\PullSnapshotsCommand;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Snapshots\UncacheableWhileDegraded;
use Inly\CoreWeb\Snapshots\WarmSnapshotsCommand;
use Inly\CoreWeb\StaticCaching\SiteScopedInvalidator;
use Inly\CoreWeb\Support\DoctorCommand;
use Inly\CoreWeb\Widgets\ContentChecksWidget;
use Inly\CoreWeb\Widgets\CoreSnapshotWidget;
use InvalidArgumentException;
use LogicException;
use Statamic\Events\RevisionSaving;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Permission;
use Statamic\Facades\User;
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
 * The `drafts` feature writes drafts of the site's entries for the sources
 * that make them (Core AI, a push), each binding how its values arrive
 * (DraftValueNormaliser) and how an update names its base (DraftBase),
 * and stages changes to content without revisions as proposals an editor
 * applies in the CP. The `checks` feature finds what is wrong in the
 * site's content (`content:check`, nightly, a dashboard widget).
 * The `forms` feature keeps the Core forms the site renders itself.
 * The `cp_link` feature links the control panel to Core under Dashboard.
 * The `cms_sign_in` feature signs the control panel in from Core alone.
 * The `content_sync` feature lets a local checkout pull the live site's
 * content as the developer's CMS user.
 * The `media` feature renders images and videos through the CDN and defines
 * the asset disks on the bucket.
 * The `cloud` feature runs the site on Laravel Cloud, its content in the
 * database, with `content:import` and `core-web:deploy`.
 * The `consent` feature is the site's own cookie banner and consent log.
 */
class CoreWebServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/core-web.php', 'core-web');

        // Now, not on booting: the eloquent driver picks its repositories in its own register(), which runs after this one.
        if (self::enabled('cloud')) {
            CloudDefaults::apply($this->app);
        }

        // Before any provider boots, once the app's config (and a test's environment) is in place; at once when registered late.
        $merge = function (): void {
            SiteCore::apply($this->app);
            MailSender::apply($this->app);

            if (self::enabled('snapshots')) {
                $this->mergeSnapshotStore();
            }

            if (self::enabled('cms_sign_in')) {
                $this->mergeCmsSignInDefaults();
                $this->mergeSsoUserProvider();
            }

            if (self::enabled('consent')) {
                $this->mergeGroupDefaults('consent', ['vendors', 'sites']);
            }

            if (self::enabled('media')) {
                $this->mergeMediaDefaults();

                // A cached config already holds the moved disks; a local one left in it was the build's choice.
                if (self::enabled('cloud') && CloudDefaults::onCloud() && ! $this->app->configurationIsCached()) {
                    CloudDefaults::movePeakDisksToBucket($this->app['config']);
                }

                MediaDisks::define($this->app);
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

        $this->app->bindIf(DraftValueNormaliser::class, StoredValues::class);
        $this->app->bind(ProposalStore::class, fn () => ProposalStore::fromConfig());
        $this->app->bind(ContentChecks::class, fn () => ContentChecks::fromConfig());
        $this->app->bindIf(CoreUsers::class);
        $this->app->bindIf(DraftBase::class, FieldsHash::class);
        $this->app->bind(SyncTokens::class, fn () => SyncTokens::fromConfig());
        $this->app->bind(DeviceAuthorizations::class, fn () => DeviceAuthorizations::fromConfig());
        $this->app->bindIf(LocalContent::class, fn () => LocalContent::fromApp());

        $this->app->bind(SiteScopedInvalidator::class, fn ($app) => new SiteScopedInvalidator(
            $app[Cacher::class],
            $app['config']['statamic.static_caching.invalidation.rules'],
        ));
    }

    /**
     * The package's media defaults under a site's `media` block, one group deep: a site that sets only its bucket
     * keeps the key and secret from env, the CDN and the layer. `disks` is the site's own list, taken whole.
     */
    private function mergeMediaDefaults(): void
    {
        $defaults = (array) (require __DIR__.'/../config/core-web.php')['media'];
        $media = (array) config('core-web.media');

        foreach ($defaults as $group => $default) {
            if ($group !== 'disks' && is_array($default) && is_array($media[$group] ?? null)) {
                $media[$group] = array_replace($default, $media[$group]);
            }
        }

        config(['core-web.media' => array_replace($defaults, $media)]);
    }

    /**
     * The package's defaults under a site's top-level block, one group deep, so a block naming only some keys keeps
     * the rest. The keys in $whole are the site's own lists, taken whole.
     *
     * @param  list<string>  $whole
     */
    private function mergeGroupDefaults(string $key, array $whole): void
    {
        $defaults = (array) (require __DIR__.'/../config/core-web.php')[$key];
        $site = (array) config("core-web.{$key}");

        foreach ($defaults as $group => $default) {
            if (! in_array($group, $whole, true) && is_array($default) && is_array($site[$group] ?? null)) {
                $site[$group] = array_replace($default, $site[$group]);
            }
        }

        config(["core-web.{$key}" => array_replace($defaults, $site)]);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/core-web.php' => config_path('core-web.php')], 'core-web-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'core-web-migrations');
        // Its own tag: re-publishing the combined one copies an already published migration again under today's date.
        $this->publishesMigrations([__DIR__.'/../database/migrations/2026_10_20_000000_create_core_web_sync_tokens_table.php' => database_path('migrations/2026_10_20_000000_create_core_web_sync_tokens_table.php')], 'core-web-sync-tokens-migration');
        $this->publishesMigrations([__DIR__.'/../database/migrations/2026_10_23_000000_create_core_web_consents_table.php' => database_path('migrations/2026_10_23_000000_create_core_web_consents_table.php')], 'core-web-consent-migration');
        // Under the driver's own names, so --force replaces copies the site published from the driver.
        $this->publishes(collect(glob(__DIR__.'/../database/cloud-migrations/*.php'))->mapWithKeys(fn (string $file): array => [$file => database_path('migrations/'.basename($file))])->all(), 'core-web-cloud-migrations');

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

        if (self::enabled('drafts')) {
            $this->bootDrafts();
        }

        if (self::enabled('checks')) {
            $this->bootChecks();
        }

        // Statamic connector API v1.2: Core reads the checks and proposes, where the site speaks the API.
        if (self::enabled('content') && (self::enabled('checks') || self::enabled('drafts'))) {
            $this->loadRoutesFrom(__DIR__.'/../routes/content.php');
        }

        if (self::enabled('forms')) {
            $this->bootForms();
        }

        if (self::enabled('cp_link')) {
            CoreNavLink::register();
        }

        if (self::enabled('cms_sign_in')) {
            $this->bootCmsSignIn();
        }

        if (self::enabled('content_sync')) {
            $this->bootContentSync();
        }

        if (self::enabled('media')) {
            $this->app->bind(UrlScheme::class, fn ($app) => $app->make((string) $app['config']->get('core-web.media.cdn.scheme')));
            MediaDisks::registerDriver();
            // Outside resources/views: under the core-web namespace, view:cache would compile them with media off.
            Blade::anonymousComponentPath(__DIR__.'/../resources/components');

            // Asset::url() on a disk reading the live site's pulled index; never on a server.
            if (self::enabled('content_sync') && $this->app->environment(['local', 'testing'])) {
                $this->loadRoutesFrom(__DIR__.'/../routes/media.php');
                (new CpThumbnails)->register();
            }

            if ($this->app->runningInConsole()) {
                $this->commands([SyncMediaCommand::class, CheckMediaCommand::class]);
            }
        }

        if (self::enabled('cloud')) {
            $this->commands([ImportContentCommand::class, DeployCommand::class, ScheduledEntriesCommand::class]);

            if (self::enabled('media') && CloudDefaults::onCloud() && MediaDisks::defines('favicons')) {
                $this->loadRoutesFrom(__DIR__.'/../routes/cloud-favicons.php');
            }

            // Only where Statamic's own every-minute job is off, which the Cloud defaults do, so an entry is never handled twice.
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                // A config published before this key existed has none, so the default lives here too.
                $cron = config('core-web.cloud.scheduled_entries.schedule', '*/15 * * * *');

                if (! config('statamic.system.handle_scheduled_entries') && is_string($cron) && $cron !== '') {
                    $schedule->command('core-web:scheduled-entries')->cron($cron)->withoutOverlapping(10);
                }
            });
        }

        // Registered whatever the features, so a blueprint importing it still opens with consent switched off.
        Fieldset::addNamespace('core-web', __DIR__.'/../resources/fieldsets');

        if (self::enabled('consent')) {
            $this->bootConsent();
        }

        if ($this->app->runningInConsole() && collect(array_keys((array) config('core-web.features')))->contains(fn (string $feature): bool => self::enabled($feature))) {
            $this->commands([DoctorCommand::class]);
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
        CoreSnapshotWidget::register();
        $this->loadViews();
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

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

    private function bootDrafts(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'core-web');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'core-web');
        $this->loadRoutesFrom(__DIR__.'/../routes/drafts.php');
        Event::listen(RevisionSaving::class, KeepDraftProvenance::class);

        Statamic::pushCpRoutes(fn () => require __DIR__.'/../routes/proposals.php');

        // Only for editors with open proposals they may apply, so the entry stays out of the way until there is one.
        Nav::extend(function ($nav): void {
            $user = User::current();
            $proposals = app(Proposals::class);
            $count = $user === null ? 0 : collect($proposals->open())->filter(fn ($proposal) => $proposals->target($proposal)?->mayEdit($user))->count();

            if ($count > 0) {
                $nav->content(__('Proposals')." ({$count})")->route('core-web.proposals.index')->icon('git-pull-request');
            }
        });
    }

    private function bootChecks(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'core-web');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'core-web');
        $this->commands([ContentCheckCommand::class]);
        ContentChecksWidget::register();

        if (is_string($cron = config('core-web.checks.schedule')) && $cron !== '') {
            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('content:check')
                ->cron($cron)
                ->withoutOverlapping()
                ->runInBackground());
        }
    }

    private function bootContentSync(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'core-web');
        $this->loadViews();
        $this->app->make(Router::class)->aliasMiddleware(AuthenticateSyncToken::ALIAS, AuthenticateSyncToken::class);
        $this->loadRoutesFrom(__DIR__.'/../routes/sync.php');

        // A local checkout's Core forms, through the live site; never on a server.
        if (self::enabled('forms') && $this->app->environment(['local', 'testing'])) {
            $this->loadRoutesFrom(__DIR__.'/../routes/forms-relay.php');
        }
        $this->commands([ContentLoginCommand::class, ContentLogoutCommand::class, ContentPullCommand::class, ContentPushCommand::class]);
        SyncLimits::register();

        Permission::extend(fn () => Permission::group('core_web', 'Core Web', function (): void {
            Permission::register(AuthenticateSyncToken::PERMISSION)
                ->label(__('core-web::sync.permission'))
                ->description(__('core-web::sync.permission_description'));
            Permission::register(AuthenticateSyncToken::PUSH_PERMISSION)
                ->label(__('core-web::sync.push_permission'))
                ->description(__('core-web::sync.push_permission_description'));
        }));

        // A site that refuses every sync call has no machines to approve or tokens to list.
        if (! GuardSyncRequests::mayActAsRemote()) {
            return;
        }

        Statamic::pushCpRoutes(fn () => require __DIR__.'/../routes/sync-cp.php');

        Nav::extend(function ($nav): void {
            $user = User::current();

            if ($user !== null && AuthenticateSyncToken::mayPull($user)) {
                $nav->tools(__('core-web::sync.tokens_title'))->route('core-web.sync.tokens')->icon('key');
            }
        });
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
        $this->loadViews();
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

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

    private function bootConsent(): void
    {
        $this->loadViews();
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        Blade::directive('consentScript', fn (string $categories): string => '<script type="text/plain" data-consent="<?php echo e('.$categories.'); ?>">');
        Blade::directive('endconsentScript', fn (): string => '</script>');

        if (! Consent::logs()) {
            return;
        }

        RateLimiter::for('core-web.consent', fn (Request $request) => Limit::perMinute(max(1, (int) config('core-web.consent.log.rate_limit', 30)))
            ->by('consent|'.sha1((string) $request->ip())));
        $this->loadRoutesFrom(__DIR__.'/../routes/consent.php');
        $this->commands([PruneConsentsCommand::class]);

        if (is_string($cron = config('core-web.consent.log.prune_schedule')) && $cron !== '') {
            $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('consent:prune')
                ->cron($cron)
                ->withoutOverlapping());
        }
    }

    private function bootForms(): void
    {
        if (! self::enabled('snapshots')) {
            throw new LogicException('core-web.features.forms needs core-web.features.snapshots: the forms are a snapshot, refreshed by Core\'s pings.');
        }

        CoreForms::classes();

        $this->loadViews();
        $this->app->extend(SnapshotRegistry::class, fn (SnapshotRegistry $registry) => $registry->register(CoreForms::snapshot(), [CoreForms::PING]));
    }

    private function loadViews(): void
    {
        if (! array_key_exists('core-web', $this->app['view']->getFinder()->getHints())) {
            $this->loadViewsFrom(__DIR__.'/../resources/views', 'core-web');
        }
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
