<?php

use Inly\CoreWeb\Checks\Checks\DeadTargets;
use Inly\CoreWeb\Checks\Checks\OwnUrlLinks;
use Inly\CoreWeb\Checks\Checks\SeoGaps;
use Inly\CoreWeb\Media\CoreCdnScheme;
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
    | `core:pull`, the degraded guard, Core's pings (`ping`), the snapshot
    | sitemaps (`sitemaps`) and the `core_snapshot` control panel widget. The
    | Core client below needs no switch.
    |
    | chat: the relay between the site's visitors and Core's chat agents
    | (`chat`), with its routes and the <x-core-web::chat> component.
    |
    | content: the site's half of the website content protocol (`content`):
    | the `core-web.envelope` route middleware its content endpoints open
    | Core's sealed calls with, and the page pings it sends Core.
    |
    | live: the site's named live reads of Core (`live`), for its pages'
    | scripts at /!/core-web/live/{name}, and for its own code through
    | Inly\CoreWeb\Live\CoreLive.
    |
    | drafts: drafts of the site's entries written as an editor's CP save
    | writes them (Inly\CoreWeb\Drafts\DraftWriter), for the sources that
    | write them (Core AI, a push), with their signed preview link (`drafts`)
    | and the provenance an editor's save keeps.
    |
    | checks: the site's content checks (`checks`) with `content:check`, its
    | nightly schedule and the `content_checks` dashboard widget. Fixing a
    | finding writes a draft or a proposal, so it needs `drafts` too.
    |
    | forms: the Core forms the site renders with its own Livewire markup
    | (Inly\CoreWeb\Forms\CoreForm), kept as a snapshot refreshed by Core's
    | `forms` ping. Needs `snapshots` on too.
    |
    | cp_link: a link to Core right under Dashboard in the control panel
    | (`cp_link`), for every CP user, opened in a new tab.
    |
    | content_sync: a developer's checkout pulling the live site's content
    | (`content_sync`), signed in as their own CMS user. On the live site, the
    | sync endpoints under /!/core-web/sync, the CP page that approves a
    | machine and the one that lists and revokes them; on the checkout,
    | `content:login`, `content:logout` and `content:pull`.
    |
    | media: the <x-core-img>, <x-core-video> and <x-core-media> components,
    | the asset disks in `media.disks` (a bucket, with a local fallback and a
    | layer outside production), `media:sync` and `media:check`.
    |
    */

    'features' => [
        'snapshots' => false,
        'chat' => false,
        'content' => false,
        'live' => false,
        'drafts' => false,
        'checks' => false,
        'forms' => false,
        'cp_link' => false,
        'content_sync' => false,
        'media' => false,
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
    | The remote Core
    |--------------------------------------------------------------------------
    |
    | Local only: the Core a local machine uses in place of its own
    | (production, as a rule), with a client Core should let read and nothing
    | more. `core:pull` copies its snapshots, and without a CORE_URL the
    | control panel link points at it. The URL and client id are the same for
    | every developer, so the site commits them as the defaults in its own
    | config/core-web.php (env can still override them); only the secret has
    | to be set (CORE_REMOTE_CLIENT_SECRET). A site config still naming this
    | `pull`, and the CORE_PULL_* variables, keep working.
    |
    */

    'remote' => [
        'base_url' => env('CORE_REMOTE_URL', env('CORE_PULL_URL', '')),
        'client_id' => env('CORE_REMOTE_CLIENT_ID', env('CORE_PULL_CLIENT_ID', '')),
        'client_secret' => env('CORE_REMOTE_CLIENT_SECRET', env('CORE_PULL_CLIENT_SECRET', '')),
        'token_cache_key' => 'core_web_remote_access_token',
    ],

    /*
    |--------------------------------------------------------------------------
    | Core's on-change pings
    |--------------------------------------------------------------------------
    |
    | A ping sealed with Core's own key (purpose website.objects, audience
    | client.client_id) opens with no secret here.
    |
    | secret: the HMAC secret a Core still on the shared secret signs its pings
    | with (WEBSITE_SYNC_SECRET in the backoffice). Empty rejects every
    | shared-secret ping; there is no APP_KEY fallback.
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
    | turn_rate_limit: messages and handoffs per minute, per session and
    | address, on top of the routes' own middleware.
    |
    | turn_rate_limit_per_address: messages and handoffs per minute from one
    | address, whatever sessions it holds, so a script that opens a session
    | per message is capped too. Visitors behind one office address share it.
    |
    | max_message_length: the longest message the relay passes on, in
    | characters; longer is `invalid` without calling Core. 10000 is Core's
    | default cap per agent (MAX_MESSAGE_LENGTH); an agent with a lower cap
    | still refuses past it, which answers `invalid` too.
    |
    | max_conversations: conversations a session may hold per agent; a new
    | subject past it is refused (`limited`) without calling Core.
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
        'turn_rate_limit' => 10,
        'turn_rate_limit_per_address' => 30,
        'max_message_length' => 10000,
        'max_conversations' => 5,
        'session_key' => 'core_web_chat',
        'cookie' => 'core_chat',
    ],

    /*
    |--------------------------------------------------------------------------
    | Website content protocol
    |--------------------------------------------------------------------------
    |
    | The site's half of the website content protocol v1.1: Core seals each
    | call to the site's content endpoints with its own key, published at
    | {core}/.well-known/core-signing-keys, for the site's client id above,
    | and the site pings Core's connector webhook route with its client token.
    |
    | connector_id: the Core connector that holds this site's pairing, which
    | the pings go to. Not a secret: a site commits it as the default in its
    | own config/core-web.php (CORE_CONTENT_CONNECTOR_ID can still override
    | it, as a staging site must: each pairing has a connector of its own).
    | Empty sends no ping.
    |
    | environment: the environment a draft or publish call must come from;
    | empty is this app's own environment. Set CORE_CONTENT_ENVIRONMENT when
    | the site runs in another APP_ENV than the Core it is paired with.
    |
    | store: the cache store the key document and the spent nonces live in,
    | shared by every server of the site; null is the default store. Pick
    | one a deploy's cache:clear leaves alone, or the spent nonces go with it.
    |
    | key_cache_key: the cache key Core's key document is kept under.
    |
    */

    'content' => [
        'connector_id' => env('CORE_CONTENT_CONNECTOR_ID', ''),
        'environment' => env('CORE_CONTENT_ENVIRONMENT'),
        'store' => null,
        'key_cache_key' => 'core_web_signing_keys',
    ],

    /*
    |--------------------------------------------------------------------------
    | Live reads
    |--------------------------------------------------------------------------
    |
    | Reads of Core made while a visitor waits, for what no snapshot can hold:
    | a calculation on the visitor's input, a figure that must be current.
    |
    | reads: each read by name (a lowercase slug) with the site's
    | Inly\CoreWeb\Live\LiveRead class. Any other name is a 404.
    |
    |     'reads' => ['loan-quote' => App\Live\LoanQuote::class],
    |
    | timeouts: seconds to connect to Core and to wait for its answer.
    |
    | retry_after: seconds a read answers at once (its stale answer, or
    | `unavailable`) after Core failed it, instead of every visitor waiting
    | out the timeouts again. 0 asks Core every time.
    |
    | store: the cache store a read's ttl() keeps answers in; null is the
    | default store.
    |
    | name / middleware: the route at /!/core-web/live/{name}, under
    | Statamic's action prefix so the static cache never stores it. It needs
    | no session, so no `web` group.
    |
    */

    'live' => [
        'reads' => [],
        'timeouts' => [
            'connect' => 3,
            'read' => 5,
        ],
        'retry_after' => 30,
        'store' => null,
        'name' => 'core-web.live',
        'middleware' => ['throttle:60,1'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Drafts
    |--------------------------------------------------------------------------
    |
    | collections: the collections a source may draft, by handle, each with
    | the blueprints a new entry may take (its default first; none named
    | allows the collection's own), whether a new entry goes under a parent
    | in the collection's tree (`structured`), and the fields an entry is
    | never published without (`published_with`). A collection not named
    | here is edited in the CP only.
    |
    |     'collections' => [
    |         'articles' => ['blueprints' => ['article'], 'published_with' => ['image']],
    |         'pages' => ['blueprints' => ['page'], 'structured' => true],
    |     ],
    |
    | preview: the signed link a draft's source hands the person, opening the
    | draft in Live Preview for `days` days at `path`, by `name`.
    |
    | proposals: where staged changes to content without revisions (navs,
    | page trees, globals, terms, asset meta) are kept until an editor applies
    | or discards them in the CP: a filesystem `disk` (null for the default)
    | and a `path` on it. A host whose disk does not outlive a deploy names a
    | bucket.
    |
    | The sentences a refused draft answers with are translations
    | (core-web::drafts); a site words them for its editors in
    | lang/vendor/core-web/{locale}/drafts.php.
    |
    */

    'drafts' => [
        'collections' => [],
        'preview' => [
            'path' => 'core-preview/{id}',
            'name' => 'core.preview',
            'days' => 7,
            'middleware' => ['throttle:60,1', 'signed:relative'],
        ],
        'proposals' => [
            'disk' => null,
            'path' => 'core-web/proposals',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Forms
    |--------------------------------------------------------------------------
    |
    | Forms the site renders with its own markup and posts to a Core form,
    | server to server on the site's client token. Every other Core form is
    | better served by Core's own embed.
    |
    | classes: the site's form classes (Inly\CoreWeb\Forms\CoreForm). The
    | definitions of the Core forms they post to are kept in the
    | `core-web-forms` snapshot. Mark each of those forms a custom embed in
    | Core, so its admins are warned before changing its fields.
    |
    |     'classes' => [App\Livewire\DamageInspection::class],
    |
    | submit_rate_limit: entries per minute from one address, per form. Core
    | does not limit what the site sends on its client token, so the site
    | limits its visitors itself.
    |
    | timeouts: seconds to connect to Core, to read a definition (from a pull,
    | never a render) and to send an entry, files included.
    |
    */

    'forms' => [
        'classes' => [],
        'submit_rate_limit' => 5,
        'timeouts' => [
            'connect' => 3,
            'definition' => 5,
            'submit' => 20,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content checks
    |--------------------------------------------------------------------------
    |
    | checks: the checks that run, each an Inly\CoreWeb\Checks\Check. A site
    | adds its own beside these, or leaves one out.
    |
    | collections: the collections whose entries are checked; null checks all.
    |
    | seo: the fields the seo-gaps check reads (Peak SEO's by default), the
    | collections it reads (null: every one with URLs) and the lengths a
    | search result shows.
    |
    | schedule: the cron expression `content:check` runs on; null schedules
    | nothing.
    |
    | disk / path: where each check's latest findings are kept.
    |
    */

    'checks' => [
        'checks' => [
            OwnUrlLinks::class,
            DeadTargets::class,
            SeoGaps::class,
        ],
        'collections' => null,
        'seo' => [
            'collections' => null,
            'title' => 'seo_title',
            'description' => 'seo_description',
            'title_max' => 60,
            'description_max' => 160,
        ],
        'schedule' => '30 3 * * *',
        'disk' => null,
        'path' => 'core-web/checks',
    ],

    /*
    |--------------------------------------------------------------------------
    | Control panel link
    |--------------------------------------------------------------------------
    |
    | The link to Core under Dashboard. It points at client.base_url (CORE_URL),
    | or on a local machine without one at the remote Core, once its client
    | is set up; with neither there is no link.
    |
    | label: what the link says, such as the backoffice's own name.
    | icon: a Statamic icon name or an SVG; null is Core's mark.
    |
    */

    'cp_link' => [
        'label' => 'Core',
        'icon' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content sync
    |--------------------------------------------------------------------------
    |
    | A local checkout pulls the live site's content into content/, signed in
    | as the developer's own CMS user with a personal token they approve in
    | the live site's CP (`php please content:login`). No shared secret.
    |
    | remote_url: the live site, the same for every developer, so a site
    | commits it here (CORE_WEB_REMOTE_SITE_URL can still override it).
    |
    | allow_local_remote: a site running as APP_ENV=local refuses every sync
    | call, so no one pulls from a developer's checkout by mistake; true lets
    | one serve as a remote (trying the flow out between two checkouts).
    |
    | token_days: how long a machine's token lives unused; every use pushes
    | its expiry this far out again.
    |
    | tokens: where the live site keeps its tokens (only their hashes): a
    | filesystem `disk` (null for the default) and a `path` on it. A host
    | whose disk does not outlive a deploy names a bucket.
    |
    | store: the cache store pending logins wait in for their approval, shared
    | by every server behind the site; null is the default store.
    |
    | middleware: in front of the sync endpoints, beside the HTTPS and
    | local-site guards. A pull reads `page_size` items a request.
    |
    | timeouts: seconds the checkout waits to connect to the live site and
    | for each answer, and for each file it reads from the site's assets.
    |
    | pull_assets: with `features.media` on a checkout without the bucket's
    | key, the asset disks list the live site's files as last pulled, read
    | through their public URLs, with uploads landing in the local layer
    | (media.layer.local). content:pull brings the index (paths, URLs and
    | `.meta`, never the files) into storage/core-web/remote-assets. False
    | keeps those disks the plain local folder.
    |
    */

    'content_sync' => [
        'remote_url' => env('CORE_WEB_REMOTE_SITE_URL', ''),
        'allow_local_remote' => false,
        'token_days' => 30,
        'tokens' => [
            'disk' => null,
            'path' => 'core-web/sync-tokens',
        ],
        'store' => null,
        'middleware' => ['throttle:120,1'],
        'page_size' => 200,
        'timeouts' => [
            'connect' => 5,
            'request' => 30,
        ],
        'pull_assets' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Media
    |--------------------------------------------------------------------------
    |
    | cdn: the transforming CDN in front of the bucket and Core's storage.
    | `origins` are its base URLs; only URLs under one get transform params,
    | every other URL (a local file, a foreign host) renders untouched. Empty
    | turns transforms off. `scheme` builds the URLs (Inly\CoreWeb\Media\UrlScheme);
    | the default speaks core-cdn's query params.
    |
    | bucket: the S3-compatible bucket the asset disks live in (DigitalOcean
    | Spaces, R2, S3). Everything but the key and secret is the same in every
    | environment, so the site commits it in its own config/core-web.php; only
    | CORE_MEDIA_KEY and CORE_MEDIA_SECRET are env. `url` is the files' public
    | base URL (the CDN's, for transforms); `root` a folder all disks share.
    | `acl` false leaves out `visibility: public` for a bucket without
    | per-object ACLs, such as R2, which rejects it; the bucket itself is then
    | public. Without a key the disks are the local folders below. A failing
    | read is bounded: 3 seconds to connect and two attempts, since rendering
    | an asset can read the bucket.
    |
    | disks: each Statamic asset disk core-web defines, by name, with the
    | folder it lives in, in the bucket and locally (public/<folder>). Leave
    | out disks Statamic addons hardcode as local (favicons, social_images).
    |
    | layer: outside production a disk never writes the bucket's own files.
    | Writes, edits and deletes land in a layer in front of it, and reads try
    | the layer first: `prefix` in the bucket (staging), or the local folder
    | `local` (the `local` environment). Files that are only in the bucket
    | cannot be deleted or moved there. A server set up before the site is
    | live (staging while editors build the content) sets CORE_MEDIA_LAYER=
    | false to write the bucket directly, and drops it at go-live.
    |
    | images: the defaults <x-core-img> renders with. `glide_presets` is the
    | srcset of a site without a CDN: its `w<px>` Glide presets, which
    | config/statamic/assets.php defines.
    |
    | videos: the widths <x-core-video> asks the CDN for, `small_width` for
    | phones, and the second of the frame it shows as the poster when none is
    | given (null: no poster). Sources the CDN does not serve play as is.
    |
    */

    'media' => [
        'cdn' => [
            'origins' => ['https://cdn.inlycore.com'],
            'scheme' => CoreCdnScheme::class,
        ],
        'bucket' => [
            'key' => env('CORE_MEDIA_KEY', ''),
            'secret' => env('CORE_MEDIA_SECRET', ''),
            'endpoint' => '',
            'region' => 'auto',
            'bucket' => '',
            'url' => '',
            'root' => '',
            'acl' => true,
            'cache_control' => 'public, max-age=2592000',
        ],
        'disks' => [
            'assets' => 'assets',
        ],
        'layer' => [
            'enabled' => env('CORE_MEDIA_LAYER', true),
            'prefix' => '_staging',
            'local' => 'media-layer',
        ],
        'images' => [
            'format' => 'auto',
            'placeholder' => 'blur',
            'glide_presets' => true,
        ],
        'videos' => [
            'width' => 1280,
            'small_width' => 720,
            'poster_time' => 0,
        ],
    ],

];
