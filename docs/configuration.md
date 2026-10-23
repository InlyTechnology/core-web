# Configuration

The environment, one family named after the product, plus the secrets a site
shares with the backoffice under the backoffice's own names:

| Env | Config key | Meaning |
|---|---|---|
| — | `core.url` / `core.client_id` | Committed, not env: the Core this site belongs to and its app client id. Fill `client.base_url` / `client.client_id` on every server where neither `CORE_URL` nor `CORE_CLIENT_ID` is set; never where `APP_ENV` is `local` or `testing`, so a checkout runs with exactly `APP_ENV=local`. A server meant to have no Core cannot opt out with an empty `CORE_URL`: once `core.url` is committed, every server not on `local` pairs with it |
| `CORE_URL` | `client.base_url` | Override only: a Core other than the committed one (a staging site paired with a staging Core, a local backoffice). A local checkout never points it at a remote Core: the live site stands in for Core there (`content:login`), and `core-web:doctor` fails on one |
| `CORE_CLIENT_ID` / `CORE_CLIENT_SECRET` | `client.client_id` / `client.client_secret` | The site's Passport client_credentials client: `CORE_URL` and `CORE_CLIENT_ID` override `core` together or not at all (one without the other leaves the client incomplete, which the doctor fails); the secret is always env |
| `CORE_CA_BUNDLE` | `client.ca_bundle` | Local only: a CA bundle for Core's self-signed certificate |
| `WEBSITE_SYNC_SECRET` | `ping.secret` | The HMAC secret a Core still on the shared secret signs pings with (the backoffice's `WEBSITE_SYNC_SECRET`). A ping sealed with Core's own key (purpose `website.objects`, audience `CORE_CLIENT_ID`) needs none, so blank it once Core seals every ping. **Empty rejects every shared-secret ping**; there is no `APP_KEY` fallback |
| `CORE_CONTENT_ENVIRONMENT` | `content.environment` | The environment a draft or publish must be sealed for, when the site runs in another `APP_ENV` than its Core (a live site on `APP_ENV=staging` paired with production Core sets `production`). Empty is `app()->environment()` |
| `CORE_CONTENT_CONNECTOR_ID` | `content.connector_id` | The id of the Core connector that holds the site's pairing; content pings go to `{core}/webhooks/connectors/{id}`. Empty sends none |
| `CORE_CMS_SITE` | `cms_sign_in.site` | This site's key among the CMS sites its Core signs into; `production` when unset |
| `CORE_MEDIA_LAYER` | `media.layer.enabled` | `false` on a server set up before the site is live (staging while the content is built): it writes the bucket directly. Remove it at go-live. On a Laravel Cloud bucket the layer is off unless this is `true` |
| `CORE_MEDIA_KEY` / `CORE_MEDIA_SECRET` | `media.bucket.key` / `media.bucket.secret` | The bucket key of a server that serves the asset disks from the bucket. Not needed locally, nor on Laravel Cloud with a bucket attached (see [Media](media.md)) |
| `LARAVEL_CLOUD_DISK_CONFIG` | `media.bucket.cloud` | Set by Laravel Cloud, never by hand: its attached buckets, used when no key is set (see [On Laravel Cloud](media.md#on-laravel-cloud)) |
| `CORE_PUBLIC_DISK` | `media.bucket.cloud_disk` | The Cloud disk the asset disks use on Laravel Cloud, when not `media` (the public bucket Core Cloud attaches to a website; see [On Laravel Cloud](media.md#on-laravel-cloud)) |
| `CORE_CDN_BASE_URL` | `media.bucket.cdn_url` | Set by Core Cloud's CDN integration: the CDN in front of the Cloud bucket, the files' URL when the site commits no `media.bucket.url` (see [On Laravel Cloud](media.md#on-laravel-cloud)) |
| `CORE_SNAPSHOT_STORE` | `snapshots.store_driver` | `file` (default) or `database`: what the `snapshots` store is (see [Where snapshots live](where-snapshots-live.md)) |

`config/core-web.php`:

| Key | Default | Meaning |
|---|---|---|
| `features.snapshots` | `false` | Switch on Core snapshots, pings and snapshot sitemaps (see [Features](features.md)) |
| `client.token_cache_key` | `core_web_access_token` | Where the access token is cached (default cache store) |
| `ping.enabled` | `true` | Register the ping route |
| `ping.path` / `ping.name` | `api/core/objects` / `api.core.objects` | The endpoint Core posts to |
| `ping.middleware` | `['throttle:120,1']` | Outside the `web` group, so no CSRF exemption is needed |
| `snapshots.store` | `snapshots` | The cache store snapshots live in. `snapshots` is added to `cache.stores` unless the app defines one by that name. `null` is the default store (tests) |
| `snapshots.store_driver` | `env('CORE_SNAPSHOT_STORE', 'file')` | `file` or `database` |
| `snapshots.database.connection` | `null` | The `database` store's connection (null: the default connection) |
| `snapshots.database.table` / `.lock_table` | `core_snapshots_cache` / `core_snapshots_cache_locks` | The `database` store's own tables |
| `snapshots.warm_schedule` | `15 3 * * *` | When `core:warm` runs; `null` schedules nothing. See [Schedule](schedule.md) |
| `snapshots.targeted_flush.enabled` | `true` | Flush only the pages a change names (see [Static cache](static-cache.md)); `false` flushes the whole site on every change |
| `snapshots.targeted_flush.max_documents` | `20` | A change to more documents than this at once flushes the whole site |
| `snapshots.uncacheable_while_degraded` | `true` | Push `UncacheableWhileDegraded` onto `statamic.web` |
| `sitemaps.enabled` / `sitemaps.name` | `true` / `core.sitemap` | Register `/sitemap-core-{type}.xml` |
| `sitemaps.middleware` | `['web', StaticCache::class]` | Statically cached like the site's pages |
| `features.chat` | `false` | Switch on the chat relay (see [Chat](chat.md)) |
| `chat.agents` | `[]` | Core agent key (a lowercase slug) => the site's `ChatContext` class, or `null` for an agent that takes no context. Any other agent is a 404 |
| `chat.path` | `api/app/chat` | Core's stock chat route |
| `chat.timeouts` | `connect` 5, `read` 8, `turn` 60 | Seconds. `turn` covers a message and the handoff summary, `read` opening and reading a conversation |
| `chat.name` / `chat.middleware` | `core-web.chat` / `['web', 'throttle:60,1']` | The relay routes (`core-web.chat.show`, `.messages`, `.handoff`, `.forget`). `web` gives them the session and CSRF |
| `chat.turn_rate_limit` | `10` | Messages and handoffs per minute, per session and address |
| `chat.turn_rate_limit_per_address` | `30` | Messages and handoffs per minute from one address, whatever sessions it holds |
| `chat.max_message_length` | `10000` | The longest message the relay passes on; longer is `invalid` without calling Core. Core's default cap per agent; an agent with a lower one still refuses past it (`invalid`) |
| `chat.max_conversations` | `5` | Conversations a session holds per agent; a new subject past it is `limited` |
| `features.content` | `false` | Switch on the website content middleware (see [Website content](website-content.md)) |
| `content.environment` | `CORE_CONTENT_ENVIRONMENT` | The environment a draft or publish must be sealed for; empty is `app()->environment()` |
| `content.store` | `null` | The cache store holding Core's keys and the spent nonces; `null` is the default store. Shared by every server behind the site, and best one a deploy's `cache:clear` and Statamic's cache clearing leave alone: clearing the nonces lets an envelope captured in the last five minutes be replayed once |
| `content.key_cache_key` | `core_web_signing_keys` | Where Core's key document is cached |
| `features.media` | `false` | Switch on the media components and disks (see [Media](media.md)) |
| `media.cdn.origins` | `['https://cdn.inlycore.com']` | The transforming CDN's base URLs; only URLs under one get transform params. `[]` turns transforms off |
| `media.cdn.scheme` | `CoreCdnScheme` | The `UrlScheme` that builds transform URLs (core-cdn's query params); `PassthroughScheme` builds none |
| `media.bucket.*` | | `endpoint`, `region` (`auto`), `bucket`, `url` (the files' public base, the CDN's), `root` (a folder every disk shares), `acl` (`true`; `false` for a bucket without object ACLs, such as R2), `cache_control` (`public, max-age=2592000`) |
| `media.disks` | `['assets' => 'assets']` | Disk name => its folder, in the bucket and in `public/` |
| `media.layer.enabled` | `CORE_MEDIA_LAYER`, unset | Outside production, keep production's files behind the layer. Unset means on, except on a Laravel Cloud bucket. A server set up before go-live sets `false` (see [The disks](media.md#the-disks)) |
| `media.layer.prefix` / `.local` | `_staging` / `media-layer` | Where the layer is: a prefix in the bucket, or `public/<local>` in the `local` environment |
| `media.images.*` | `format` `auto`, `placeholder` `blur` | `<x-core-img>`'s defaults |
| `media.videos.*` | `width` 1280, `small_width` 720, `poster_time` 0 | `<x-core-video>`'s CDN widths and frame poster (`null`: none) |
| `chat.session_key` / `chat.cookie` | `core_web_chat` / `core_chat` | Where conversations live in the session; the hint cookie's prefix (`core_chat_{agent}`) |
| `features.cms_sign_in` | `false` | Switch on CMS sign-in (see [CMS sign-in](cms-sign-in.md)) |
| `cms_sign_in.site` | `CORE_CMS_SITE`, else `production` | This site's key among the CMS sites Core signs into |
| `cms_sign_in.roles` | `[]` | Core CMS grant => Statamic role handle. An unmapped grant is inert; a sign-in with nothing mapped (and not an admin) is refused |
| `cms_sign_in.local_password_sign_in` | `false` | With `APP_ENV=local`, restore Statamic's own login for development without Core |
| `cms_sign_in.path` / `.name` / `.middleware` | `admin-sso` / `core-web.cms-sign-in` / `['web', 'throttle:core-web.cms-sign-in']` | The hand-off route, always in `web` (it signs into a session); `rate_limit` (10) a minute per address |
| `cms_sign_in.revoke.*` | `api/core/cms-users/revoke`, `core-web.cms-users.revoke`, `['throttle:120,1']` | The offboarding route, outside the `web` group |
| `cms_sign_in.reconcile_schedule` | `45 3 * * *` | When `core:reconcile-cms-users` runs; `null` schedules nothing |
| `cms_sign_in.core.*` | `/sso/cms`, `/api/app/cms-sso/client/verify`, `/api/app/cms-users/client/reconcile` | Core's paths |
