<?php

use Statamic\StaticCaching\Middleware\Cache as StaticCache;

return [

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

];
