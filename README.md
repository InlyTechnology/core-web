# inly/core-web

The website half of an Inly Core integration, for Statamic sites that show data
an Inly Core backoffice owns. Core stays the source of truth; the site keeps a
**snapshot** of the objects it shows in its own cache, refreshes it on a
schedule and whenever Core **pings** a change, and never pulls from Core while
a visitor waits (unless the cache is completely cold).

What the package gives a site:

| Piece | Class | What it does |
|---|---|---|
| Snapshot definition | `Inly\CoreWeb\Snapshots\Snapshot` | Where a snapshot lives in the cache, its TTLs, how to pull it whole and one object, partitions (per site), empty guard, change listeners, sitemap, pages |
| Snapshot store | `Inly\CoreWeb\Snapshots\SnapshotStore` | Three cache layers (fresh marker, last good, backoff), cold-pull lock, write lock, single-object patching, no-op writes skipped, change fingerprints and change sets |
| Registry | `Inly\CoreWeb\Snapshots\SnapshotRegistry` | Every snapshot by type, which ping types refresh which, idle ping types, custom warmers and jobs |
| `core:warm` | `Inly\CoreWeb\Snapshots\WarmSnapshotsCommand` | Pulls every registered snapshot, or one; scheduled nightly by default, as the safety net under Core's pings |
| Refresh job | `Inly\CoreWeb\Snapshots\RefreshSnapshotJob` | What a ping queues: patches one object, or warms the type |
| Degraded guard | `Inly\CoreWeb\Snapshots\UncacheableWhileDegraded` | Keeps a page built on an empty degraded read out of Statamic's static cache |
| Core client | `Inly\CoreWeb\Client\CoreClient` | Passport client_credentials client for Core's Objects API (JSON:API) |
| Ping endpoint | `Inly\CoreWeb\Ping\PingController`, `PingSignature` | `POST api/core/objects`, HMAC-signed by Core |
| Snapshot pages | `Inly\CoreWeb\Snapshots\SnapshotPage` | For a snapshot that declares `->pages()`: find a document for the site's own route (503 while degraded, Statamic's 404 when gone), 301 a stray path to its page, render through Statamic's View |
| Snapshot sitemaps | `Inly\CoreWeb\Sitemaps\*` | `/sitemap-core-{type}.xml` per site host, and the `{{ core_sitemaps }}` tag |
| Static cache helpers | `Inly\CoreWeb\StaticCaching\SiteCache`, `SiteScopedInvalidator` | Flush the pages a snapshot change touches (or the whole site when they are not known); scope the `all` invalidation rule to the saved item's site |
| Rich text | `Inly\CoreWeb\Support\CoreHtml` | Narrows Core's `*_html` fields to prose markup |
| Chat relay | `Inly\CoreWeb\Chat\*` | A site's visitors talking to Core's chat agents: the transport (`CoreChat`), the relay routes, the per-agent `ChatContext`, a headless Alpine factory and `<x-core-web::chat>` |
| Own form markup | `Inly\CoreWeb\Forms\*` | A Livewire form with the site's own markup posting to a Core form (`CoreForm`): defaults, validation and missing required fields from Core's definition, and the entry sent on the site's token |
| Website content | `Inly\CoreWeb\Content\*` | Opens the envelopes Core seals with its own key for a site's content endpoints (`core-web.envelope:<purpose>`), reads Core's published keys, and pings Core when a page changes |
| CMS sign-in | `Inly\CoreWeb\CmsSignIn\*` | The control panel signed into from Core alone: the hand-off and offboarding routes, a user provider that admits only the accounts Core provisioned, the CP login redirected to Core, and `core:reconcile-cms-users` |
| Media | `Inly\CoreWeb\Media\*` | `<x-core-img>`, `<x-core-video>`, `<x-core-media>` over the CDN (`CoreCdnScheme`), asset disks on the bucket with a layer outside production (`MediaDisks`, `LayeredAdapter`), `media:sync`, `media:check` |
| Mail sender | `Inly\CoreWeb\Mail\MailSender` | The From address built as `local_part@domain` from committed config, each part overridable by env |
| Cookie consent | `Inly\CoreWeb\Consent\*` | The site's own cookie banner, settings dialog and cookie declaration, scripts and embeds held back per category, Google Consent Mode v2, and a consent log as proof of consent |
| Test helpers | `Inly\CoreWeb\Testing\FakesCore`, `CoreEnvelopeSealer` | A fake Core over `Http::fake()`, pings signed as Core signs them, and content calls sealed with a test key published as Core's |

What stays in the site: the domain snapshots (which Core types, what a stored
document looks like, how the pages read it), the page routes and views, the TTL
choices, the chat's markup, context and lead handling, and anything else that
is not plumbing.

## Installation

```bash
composer require inly/core-web
```

The service provider is auto-discovered and the defaults read the env names
in [Configuration](docs/configuration.md). Requirements: PHP 8.3+ with `ext-sodium`, Laravel 12 or 13, Statamic 6.31+.

## Documentation

One page per feature, in [`docs/`](docs):

- [Features](docs/features.md)
- [Configuration](docs/configuration.md)
- [Where snapshots live](docs/where-snapshots-live.md)
- [Registering snapshots](docs/registering-snapshots.md)
- [Reading Core](docs/reading-core.md)
- [Schedule](docs/schedule.md)
- [Pulling production data](docs/pulling-production-data.md)
- [Ping contract](docs/ping-contract.md)
- [Sitemaps](docs/sitemaps.md)
- [Pages](docs/pages.md)
- [Control panel widget](docs/control-panel-widget.md)
- [Static cache](docs/static-cache.md)
- [Chat](docs/chat.md)
- [Live reads](docs/live-reads.md)
- [Forms](docs/forms.md)
- [Media](docs/media.md)
- [Website content](docs/website-content.md)
- [Drafts](docs/drafts.md)
- [Content checks](docs/content-checks.md)
- [Control panel link](docs/control-panel-link.md)
- [CMS sign-in](docs/cms-sign-in.md)
- [Content pull](docs/content-pull.md)
- [Content push](docs/content-push.md)
- [Mail sender](docs/mail-sender.md)
- [Laravel Cloud](docs/laravel-cloud.md)
- [Cookie consent](docs/cookie-consent.md)
- [SEO](docs/seo.md)
- [Favicons](docs/favicons.md)
- [Translation](docs/translation.md)
- [Statamic helpers](docs/statamic-helpers.md)
- [Checking a site's setup](docs/checking-a-sites-setup.md)
- [Agent instructions](docs/agent-instructions.md)
- [Testing a site](docs/testing-a-site.md)
- [Site rules](docs/site-rules.md)

## Developing

```bash
composer install
composer test     # PHPUnit
composer format   # Pint
```

CI runs the suite and `pint --test` on every push and pull request.

## Releasing

Releases are git tags (`v0.3.0`); Packagist picks each one up. A pull
request adds its notes to `CHANGELOG.md` under `## Unreleased` (one section,
at the top). To release, run the **Release** workflow from the Actions tab
(it works in the GitHub mobile app) with `patch`, `minor`, `major` or an
exact version. It requires the Tests workflow to have passed on `main`,
renames `## Unreleased` to the version, commits that to `main`, tags that
commit and publishes the GitHub release with the section as its notes. It
refuses an empty or missing `## Unreleased`, an existing or lower version,
and, before 1.0, a patch release that carries upgrade notes.
If the publish step fails after the push, publish the existing tag by hand:
`gh release create vX.Y.Z --verify-tag --title vX.Y.Z --notes-file <notes>`.

## License

MIT. See [LICENSE](LICENSE).
