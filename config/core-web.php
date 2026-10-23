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
    | content: the site's half of the Statamic connector API (`content`):
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
    | cms_sign_in: the control panel signed into from Core alone
    | (`cms_sign_in`): the hand-off and offboarding routes, the user provider
    | that admits only the accounts Core provisioned, the redirect of the CP
    | login to Core, and `core:reconcile-cms-users` with its schedule.
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
    | cloud: on Laravel Cloud, the config a site needs there (`cloud`), with
    | `content:import` and `core-web:deploy` everywhere.
    |
    | consent: the site's own cookie consent (`consent`): the banner and
    | preference centre, the cookie declaration, scripts and embeds held back
    | until their category is granted, Google Consent Mode v2, the consent
    | log route and `consent:prune` with its schedule.
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
        'cms_sign_in' => false,
        'content_sync' => false,
        'media' => false,
        'cloud' => false,
        'consent' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail sender
    |--------------------------------------------------------------------------
    |
    | The From address is `local_part@domain`, committed: a site sets only its
    | local part (`acme`, `no-reply`), and the domain is the agency's
    | transactional domain, whose Resend key the site sends with. It fills
    | mail.from.address while that is unset or Laravel's `hello@example.com`;
    | MAIL_FROM_ADDRESS still wins. No local part leaves mail.from alone.
    |
    */

    'mail' => [
        'from' => [
            'local_part' => env('MAIL_FROM_LOCAL_PART'),
            'domain' => env('MAIL_FROM_DOMAIN', 'transactional.inly.dev'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | This site's Core
    |--------------------------------------------------------------------------
    |
    | The Core this site belongs to and its app client id, committed: the same
    | on every server, so no environment sets them. They fill client.base_url
    | and client.client_id wherever CORE_URL / CORE_CLIENT_ID are unset, except
    | where APP_ENV is local or testing (a checkout reaches production through
    | the live site, and a test never reaches a real Core).
    |
    */

    'core' => [
        'url' => '',
        'client_id' => '',
    ],

    /*
    |--------------------------------------------------------------------------
    | Core API client
    |--------------------------------------------------------------------------
    |
    | The Inly Core install this site reads, server to server, with a Passport
    | client_credentials client (Inly\CoreWeb\Client\CoreClient). CORE_URL and
    | CORE_CLIENT_ID override `core` above where an environment pairs with
    | another Core (a staging Core, a local backoffice); CORE_CLIENT_SECRET is
    | always env.
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
    | Statamic connector API
    |--------------------------------------------------------------------------
    |
    | The site's half of the Statamic connector API v1.1: Core seals each
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
    | relay_max_upload_bytes: the files one entry may carry through the form
    | relay of a local checkout (README → Forms on a local checkout).
    |
    */

    'forms' => [
        'classes' => [],
        'submit_rate_limit' => 5,
        'relay_max_upload_bytes' => 10 * 1024 * 1024,
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
    | The link to Core under Dashboard. It points at client.base_url (CORE_URL);
    | without it there is no link.
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
    | CMS sign-in
    |--------------------------------------------------------------------------
    |
    | The CP has no passwords of its own: Core signs people in, sealing each
    | hand-off with its own key for this site's app client (client.client_id),
    | and the site confirms it with Core on that client's token, so Core's
    | answer, not the hand-off, decides the grant. Admins become super users;
    | everyone else gets the Statamic roles their Core CMS grants map to.
    |
    | site: this site's key among the CMS sites Core signs into. Not a secret:
    | `production` unless a site commits another, and CORE_CMS_SITE can still
    | override it (a staging site paired with the production Core does).
    |
    | roles: Core CMS grants → Statamic role handles. Core decides WHO may
    | enter the CP, this map decides WHAT a grant means here. A grant left
    | unmapped is inert, and a sign-in with nothing mapped is refused.
    |
    | local_password_sign_in: with APP_ENV `local` as well, restores
    | Statamic's own login, for development without any Core.
    |
    | path / name / middleware: the hand-off route, the address Core sends
    | the browser to. `web` gives it the session it signs into; the limiter
    | caps the unauthenticated calls to Core it costs (rate_limit a minute).
    |
    | revoke: the route Core posts offboarding notices to.
    |
    | reconcile_schedule: the cron `core:reconcile-cms-users` runs on, the
    | sweep under a lost notice; null schedules nothing.
    |
    | core: Core's own paths for signing in, confirming a hand-off and
    | reconciling.
    |
    */

    'cms_sign_in' => [
        'site' => env('CORE_CMS_SITE') ?: 'production',
        'roles' => [],
        'local_password_sign_in' => false,
        'path' => 'admin-sso',
        'name' => 'core-web.cms-sign-in',
        'middleware' => ['web', 'throttle:core-web.cms-sign-in'],
        'rate_limit' => 10,
        'revoke' => [
            'path' => 'api/core/cms-users/revoke',
            'name' => 'core-web.cms-users.revoke',
            'middleware' => ['throttle:120,1'],
        ],
        'reconcile_schedule' => '45 3 * * *',
        'core' => [
            'sign_in' => '/sso/cms',
            'verify' => '/api/app/cms-sso/client/verify',
            'reconcile' => '/api/app/cms-users/client/reconcile',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Content sync
    |--------------------------------------------------------------------------
    |
    | A local checkout pulls the live site's content into content/, signed in
    | as the developer's own CMS user with a personal token they approve in
    | the live site's CP (`php artisan content:login`). No shared secret.
    |
    | remote_url: the live site, the same for every developer, so a site
    | commits it here, never in .env.
    |
    | allow_local_remote: a site running as APP_ENV=local refuses every sync
    | call, so no one pulls from a developer's checkout by mistake; true lets
    | one serve as a remote (trying the flow out between two checkouts).
    |
    | token_days: how long a machine's token lives unused; every use pushes
    | its expiry this far out again.
    |
    | tokens: where the live site keeps its tokens (only their hashes).
    | `driver` files: one file each on a filesystem `disk`, under `path`; a
    | null disk is a private local folder, storage/app/private/{path}, and a
    | named one is private, never the site's public bucket. `driver`
    | database: the `table` (from the core-web-sync-tokens-migration tag) on
    | `connection`, null for the default. A site behind more than one server,
    | or on a host whose disk does not outlive a deploy (Laravel Cloud), sets
    | database: a local file is seen by one server only, until the next deploy.
    |
    | store: the cache store pending logins wait in for their approval, shared
    | by every server behind the site (database or redis there, never file);
    | null is the default store.
    |
    | middleware: in front of the sync endpoints, beside the HTTPS and
    | local-site guards; `core-web-sync` limits each token and address, and
    | starting a login has a limiter of its own. A pull reads `page_size`
    | items (or snapshot documents) a request.
    |
    | timeouts: seconds the checkout waits to connect to the live site and
    | for each answer, and for each file it reads from the site's assets.
    |
    | pull_assets: with `features.media` on a local checkout without the
    | bucket's key, the asset disks list the live site's files as last
    | pulled, read through their public URLs, under the committed folder and
    | the local layer uploads land in (media.layer.local). content:pull
    | brings the index (paths, URLs and `.meta`, never the files) into
    | storage/core-web/remote-assets. False keeps those disks the plain
    | local folder.
    |
    | push: whether this site takes `content:push` from a checkout: entries
    | as drafts (working copies, new entries unpublished; the collections in
    | drafts.collections), globals and navigation trees as proposals (with
    | features.drafts on, whose CP page decides them). Pushing needs the
    | "Push content" permission. False keeps the site pull only.
    | push_items: the most files one push carries.
    | push_bytes: the most content (all files together) one push carries.
    | push_asset_bytes: the largest asset file a push uploads.
    |
    */

    'content_sync' => [
        'remote_url' => '',
        'allow_local_remote' => false,
        'token_days' => 30,
        'tokens' => [
            'driver' => 'files',
            'disk' => null,
            'path' => 'core-web/sync-tokens',
            'connection' => null,
            'table' => 'core_web_sync_tokens',
        ],
        'store' => null,
        'middleware' => ['throttle:core-web-sync'],
        'page_size' => 200,
        'timeouts' => [
            'connect' => 5,
            'request' => 30,
        ],
        'pull_assets' => true,
        'push' => true,
        'push_items' => 200,
        'push_bytes' => 10 * 1024 * 1024,
        'push_asset_bytes' => 20 * 1024 * 1024,
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
    | public. Without a key, the bucket Laravel Cloud attaches is used
    | (`cloud`, which Cloud sets; the one on disk `cloud_disk`, the public
    | bucket Core Cloud attaches to a website, else the first), without ACLs, and with
    | `url` taken from here when set, else `cdn_url` (the CDN Core Cloud puts
    | in front of that public bucket, used only when it was found by name),
    | else the bucket's own. Without either the disks are the local folders below. A failing
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
    | false to write the bucket directly, and drops it at go-live. Unset, a
    | Laravel Cloud bucket (attached per environment) gets no layer.
    |
    | images: the defaults <x-core-img> renders with.
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
            'cloud' => env('LARAVEL_CLOUD_DISK_CONFIG', ''),
            'cloud_disk' => env('CORE_PUBLIC_DISK', 'media'),
            'cdn_url' => env('CORE_CDN_BASE_URL', ''),
        ],
        'disks' => [
            'assets' => 'assets',
        ],
        'layer' => [
            'enabled' => env('CORE_MEDIA_LAYER'),
            'prefix' => '_staging',
            'local' => 'media-layer',
        ],
        'images' => [
            'format' => 'auto',
            'placeholder' => 'blur',
        ],
        'videos' => [
            'width' => 1280,
            'small_width' => 720,
            'poster_time' => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Laravel Cloud
    |--------------------------------------------------------------------------
    |
    | On Laravel Cloud (LARAVEL_CLOUD=1, which Cloud sets) every deploy resets
    | the disk, so the feature moves what must outlive one into the database:
    | content and CP users on statamic/eloquent-driver (with the site's
    | published config/statamic/eloquent-driver.php), cache, sessions, the
    | maintenance flag and the snapshots store. It also turns off the stache
    | watcher and Statamic's git integration and statically caches pages
    | (`half`). An env var the environment sets keeps its own value. Off
    | Cloud nothing changes.
    |
    | deploy: artisan commands `core-web:deploy` runs after its own steps
    | (migrate, stache, static cache, search, core:warm), such as
    | ['inly:robots'].
    |
    | detected: null asks Cloud's LARAVEL_CLOUD; true or false forces it.
    |
    | scheduled_entries: Statamic's own job runs every minute, which keeps a
    | Cloud site from ever sleeping, so on Cloud it is off and
    | `core-web:scheduled-entries` runs on `schedule` instead (Cloud's
    | scheduler wake-up interval), covering every entry dated since its last
    | run, at most `lookback_minutes` back. An empty `schedule` runs nothing.
    |
    */

    'cloud' => [
        'deploy' => [],
        'detected' => null,
        'scheduled_entries' => [
            'schedule' => '*/15 * * * *',
            'lookback_minutes' => 1440,
            'first_run_minutes' => 15,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cookie consent
    |--------------------------------------------------------------------------
    |
    | The site's own cookie banner, a light consent tool for a site that needs
    | no certified CMP (IAB TCF). Everything here is the same in every
    | environment, so it is committed; nothing is read from env.
    |
    | vendors: what the site loads that sets cookies, as keys of the catalogue
    | in Inly\CoreWeb\Consent\ConsentVendors (google_analytics, hubspot, ...)
    | or as a definition of its own: ['booking' => ['name' => 'Booking',
    | 'category' => 'marketing', 'cookies' => [['name' => 'bk_id', 'purpose'
    | => '...', 'days' => 30]]]]. A catalogue key with an array merges over
    | the catalogue's entry. The site's session and CSRF cookies and the
    | consent cookie itself are always declared as necessary.
    |
    | policy_version: raise it when the site adds a vendor or changes what it
    | asks for, and every visitor is asked again.
    |
    | renew_after_days: a choice older than this is asked for again.
    |
    | policy_url: the site's cookie or privacy page, linked from the banner.
    | A component's `policy-url` attribute wins (a per-site pin).
    |
    | global: a global set whose blueprint imports `core-web::consent`, so
    | editors set the banner's title, text and cookie page in the CP, per
    | site. A field left empty keeps the default text, or `policy_url`.
    |
    | google: Consent Mode v2 defaults, all denied until the visitor grants.
    | `mode` basic holds Google's tags back until statistics or marketing is granted;
    | advanced loads them at once and lets them send cookieless pings.
    |
    | log: proof of consent, one row per choice in the site's own database,
    | with a random consent id and no IP address or user. Publish the
    | migration (--tag=core-web-consent-migration). Rows older than
    | `retention_days` are deleted by `consent:prune` on `prune_schedule`.
    |
    | sites: per-site overrides on a multisite install, keyed by site handle,
    | holding any key above but `log` and `cookie`: ['trucks' => ['vendors'
    | => [...], 'branding' => ['accent' => '#c00']]]. Each domain asks on its
    | own, since a cookie belongs to one domain, and its log rows name it.
    |
    | branding: the banner wears Inly Core's look. A site overrides any of
    | it: `accent` and `accent_foreground` (colours), `background`,
    | `foreground`, `font` (a CSS font-family), `logo` (an image URL shown
    | beside the title) and `show_mark` (the "Core Consent" line).
    |
    | button: once the visitor has chosen, a small round button in a corner
    | reopens the settings, as the law asks withdrawing to be as easy as
    | giving: `bottom-left`, `bottom-right`, or false for none (the site then
    | links <x-core-web::consent.settings-link> itself, as in its footer).
    |
    */

    'consent' => [
        'vendors' => [],
        'sites' => [],
        'policy_version' => 1,
        'renew_after_days' => 365,
        'policy_url' => null,
        'global' => null,
        'cookie' => 'core_consent',
        'google' => [
            'consent_mode' => true,
            'mode' => 'basic',
        ],
        'log' => [
            'enabled' => true,
            'connection' => null,
            'table' => 'core_web_consents',
            'retention_days' => 1825,
            'prune_schedule' => '17 3 * * *',
            'rate_limit' => 30,
        ],
        'branding' => [
            'accent' => null,
            'accent_foreground' => null,
            'background' => null,
            'foreground' => null,
            'font' => null,
            'logo' => null,
            'show_mark' => true,
        ],
        'button' => 'bottom-left',
    ],
];
