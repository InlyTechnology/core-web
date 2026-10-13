<?php

use Statamic\StaticCaching\Middleware\Cache as StaticCache;

return [

    /*
    |--------------------------------------------------------------------------
    | Features
    |--------------------------------------------------------------------------
    |
    | Every feature is off until the site switches it on, and a feature that is
    | off registers nothing: no route, schedule, middleware, command, tag or
    | cache store. A
    | site switches one on in its own config/core-web.php; that file needs only
    | the keys it changes, since every other top-level key keeps the defaults
    | below:
    |
    |     return ['features' => ['snapshots' => true]];
    |
    | snapshots: Core snapshots with their store, `core:warm` and its schedule,
    | `core:pull`, the degraded guard, Core's pings (`ping`) and the snapshot
    | sitemaps (`sitemaps`). The Core client below needs no switch.
    |
    | chat: the relay between the site's visitors and Core's chat agents
    | (`chat`), with its routes and the <x-core-web::chat> component.
    |
    */

    'features' => [
        'snapshots' => false,
        'chat' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Core API client
    |--------------------------------------------------------------------------
    |
    | The Inly Core install this site reads, server to server, with a Passport
    | client_credentials client (Inly\CoreWeb\Client\CoreClient): CORE_URL,
    | CORE_CLIENT_ID and CORE_CLIENT_SECRET.
    |
    | ca_bundle: local only, a CA bundle for Core's self-signed certificate
    | (CORE_CA_BUNDLE).
    | token_cache_key: where the access token is cached (default cache store).
    |
    */

    'client' => [
        'base_url' => env('CORE_URL', ''),
        'client_id' => env('CORE_CLIENT_ID', ''),
        'client_secret' => env('CORE_CLIENT_SECRET', ''),
        'ca_bundle' => env('CORE_CA_BUNDLE', ''),
        'token_cache_key' => 'core_web_access_token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pulling another Core's data
    |--------------------------------------------------------------------------
    |
    | Local only: the Core `core:pull` reads the snapshots from (production, as
    | a rule) and its own client, which Core should let read and nothing more.
    | The URL and client id are the same for every developer, so the site
    | commits them as the defaults in its own config/core-web.php (env can
    | still override them); only the secret has to be set
    | (CORE_PULL_CLIENT_SECRET). The copies it stores are pinned until the
    | next pull or `core:pull --release`.
    |
    */

    'pull' => [
        'base_url' => env('CORE_PULL_URL', ''),
        'client_id' => env('CORE_PULL_CLIENT_ID', ''),
        'client_secret' => env('CORE_PULL_CLIENT_SECRET', ''),
        'token_cache_key' => 'core_web_pull_access_token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Core's on-change pings
    |--------------------------------------------------------------------------
    |
    | secret: the HMAC secret Core signs its pings with (WEBSITE_SYNC_SECRET in
    | the backoffice). Empty rejects every ping; there is no APP_KEY fallback.
    |
    | path / name / middleware: the endpoint Core posts to. Outside the `web`
    | group, so it needs no CSRF exemption.
    |
    */

    'ping' => [
        'enabled' => true,
        'secret' => env('WEBSITE_SYNC_SECRET', ''),
        'path' => 'api/core/objects',
        'name' => 'api.core.objects',
        'middleware' => ['throttle:120,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Snapshots
    |--------------------------------------------------------------------------
    |
    | store: the cache store snapshots live in. `snapshots` is added to
    | cache.stores unless the app defines one by that name, apart from the
    | default store so a deploy's cache:clear leaves what the site serves
    | through a Core outage alone. Null uses the default store (tests).
    |
    | store_driver: what the added `snapshots` store is (CORE_SNAPSHOT_STORE).
    | `file` keeps it in storage/framework/snapshots; `database` keeps it in
    | its own table (publish the migration: --tag=core-web-migrations), for
    | hosts whose disk does not outlive a deploy, such as Laravel Cloud. Not a
    | Redis store on the default cache database: cache:clear flushes it.
    |
    | database: the connection (null for the default) and the tables of the
    | `database` store.
    |
    | warm_schedule: the cron expression `core:warm` runs on; null schedules
    | nothing (the app schedules it itself). Core pings every change and a
    | pull that changed nothing writes nothing, so the schedule is the safety
    | net under a lost ping: nightly. Give each snapshot a fresh TTL longer
    | than the interval (and a stale one longer still), or reads fall back to
    | the stale copy between runs and a ping pulls whole instead of patching.
    |
    | targeted_flush: a snapshot that names the pages its documents appear on
    | (Snapshot::dependents()) flushes only those pages on a change, as long
    | as no more than max_documents changed at once; past that, or with
    | `enabled` false, every change flushes the whole site.
    |
    | uncacheable_while_degraded: marks a page rendered from a snapshot that
    | had nothing to serve as uncacheable for Statamic's static cache (pushed
    | onto the statamic.web middleware group).
    |
    */

    'snapshots' => [
        'store' => 'snapshots',
        'store_driver' => env('CORE_SNAPSHOT_STORE', 'file'),
        'database' => [
            'connection' => null,
            'table' => 'core_snapshots_cache',
            'lock_table' => 'core_snapshots_cache_locks',
        ],
        'warm_schedule' => '15 3 * * *',
        'targeted_flush' => [
            'enabled' => true,
            'max_documents' => 20,
        ],
        'uncacheable_while_degraded' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Snapshot sitemaps
    |--------------------------------------------------------------------------
    |
    | /sitemap-core-{type}.xml at every site's address (under its path when
    | it shares a host with other sites; the second route is `name`.site),
    | for each snapshot that declares a sitemap (Snapshot::sitemap()). The
    | {{ core_sitemaps }} tag lists the ones with URLs for robots.txt and
    | the sitemap index.
    |
    */

    'sitemaps' => [
        'enabled' => true,
        'name' => 'core.sitemap',
        'middleware' => ['web', StaticCache::class],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat
    |--------------------------------------------------------------------------
    |
    | agents: the Core agents the site's visitors may talk to, by Core's agent
    | key, each with the site's Inly\CoreWeb\Chat\ChatContext class (null for
    | an agent that takes no context). Any other agent is a 404.
    |
    |     'agents' => ['assistant' => App\Chat\AssistantContext::class],
    |
    | path: Core's stock chat route, `{path}/{agent}/conversations`.
    |
    | timeouts: seconds. `turn` covers a message (the model runs while Core
    | answers) and the handoff summary; `read` opening and reading a
    | conversation. The web server's own read timeout must outlast `turn`.
    |
    | name / middleware: the site's relay routes, under Statamic's action
    | prefix (`/!/core-web/chat/{agent}`). `web` gives them the session the
    | access tokens live in, and CSRF.
    |
    | session_key / cookie: where conversations are kept in the session, and
    | the prefix of the per-agent cookie that tells the front end one exists.
    |
    */

    'chat' => [
        'agents' => [],
        'path' => 'api/app/chat',
        'timeouts' => [
            'connect' => 5,
            'read' => 8,
            'turn' => 60,
        ],
        'name' => 'core-web.chat',
        'middleware' => ['web', 'throttle:60,1'],
        'session_key' => 'core_web_chat',
        'cookie' => 'core_chat',
    ],

];
