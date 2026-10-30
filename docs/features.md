# Features

Installed, the package does nothing: every feature is off until the site
switches it on, and a feature that is off registers no route, schedule,
middleware, command, tag or cache store. That is what lets every new site
install it whether or not it shows Core data yet. The one exception is the
`core-web.envelope` middleware alias, registered whatever the switches so a
site's own routes can name it; while `features.content` is off it opens
nothing.

| Feature | Switch | What it registers |
|---|---|---|
| Core snapshots | `features.snapshots` | The `snapshots` cache store, `core:warm` and its schedule, `core:pull`, the degraded guard, the ping route (`ping.enabled`) and the snapshot sitemaps with their `{{ core_sitemaps }}` tag (`sitemaps.enabled`), and the `core_snapshot` dashboard widget (see [Control panel widget](control-panel-widget.md)) |
| Chat | `features.chat` | The relay routes under `/!/core-web/chat/{agent}`, the `core-web` view namespace with `<x-core-web::chat>`, and the hint cookies' exemption from cookie encryption (see [Chat](chat.md)) |
| Live reads | `features.live` | The route `/!/core-web/live/{name}` for the reads named in `live.reads` (see [Live reads](live-reads.md)) |
| Drafts | `features.drafts` | The signed preview route (`drafts.preview.path`), the `core-web::drafts` translations and the listener that keeps a draft's provenance through an editor's save, and the `core_drafts` dashboard widget (see [Drafts](drafts.md)) |
| Content checks | `features.checks` | `content:check` and its nightly schedule (`checks.schedule`), the `content_checks` dashboard widget (see [Content checks](content-checks.md)) |
| Forms | `features.forms` | The `core-web-forms` snapshot on Core's `forms` ping and the `core-web` view namespace (see [Forms](forms.md)); needs `features.snapshots` |
| CMS sign-in | `features.cms_sign_in` | The hand-off route (`/admin-sso`) and the offboarding route, the `core-web-sso` user provider on the CP guard, the global middleware that sends the CP login to Core, and `core:reconcile-cms-users` with its schedule (see [CMS sign-in](cms-sign-in.md)) |
| Control panel link | `features.cp_link` | A link to Core right under Dashboard in the control panel (see [Control panel link](control-panel-link.md)) |
| Laravel Cloud | `features.cloud` | On Laravel Cloud, the config a site needs there; everywhere, `content:import` and `core-web:deploy` (see [Laravel Cloud](laravel-cloud.md)) |
| Media | `features.media` | `<x-core-img>`, `<x-core-video>` and `<x-core-media>`, the asset disks in `media.disks` (unless the app defines them), the `core-web-layered` disk driver, `media:sync`, `media:check` and the `missing_alt_text` dashboard widget; with `features.content_sync` on a `local` or `testing` app, the `core-web-remote-index` driver and the `/!/core-web/media` route `Asset::url()` resolves through (see [Media](media.md)) |
| Website content | `features.content` | Lets the `core-web.envelope` route middleware open envelopes; switched off, it refuses every call. The site registers its own content routes behind it (see [Website content](website-content.md)) |
| Cookie consent | `features.consent` | The `<x-core-web::consent.*>` components, the `@consentScript` directive, the consent log route `POST /!/core-web/consent` with `consent:prune` and its schedule, `consent:show`, the control panel's **Consent log** utility and the `consent_stats` dashboard widget (`consent.log.enabled`) (see [Cookie consent](cookie-consent.md)) |
| SEO | `features.seo` | The `<x-core-web::seo.head>` component, `/robots.txt`, `/sitemap.xml` and `/sitemaps.xml`, the redirects middleware on front-end 404s, the page-not-found entry and the sitemap cache's flush on save; Peak SEO must be removed (see [SEO](seo.md)) |
| Favicons | `features.favicons` | The `<x-core-web::favicons.head>` component, `/site.webmanifest` and `/favicon.ico`, every size cut from the `favicons` global's PNG by the CDN or Glide (see [Favicons](favicons.md)) |
| Buttons | `features.buttons` | The `core-web::button` fieldset built from the `buttons` config, `Button::resolve()`, and with `features.snapshots` the `core_snapshot_pages` dictionary for links to Core's pages (see [Buttons](buttons.md)) |
| Translation | `features.translation` | A translation that follows its original: its replicators and grids keep the original's blocks and own only their texts, with outdated texts shown in the CP and the `translation-sync` check (see [Translation](translation.md)) |
| Content pull | `features.content_sync` | On the live site, the sync endpoints under `/!/core-web/sync` (`core-web.sync` middleware), the CP page that approves a machine and the **Tools → Content sync** page that lists and revokes them; on a checkout, `content:login`, `content:logout`, `content:pull`, `content:push` and `content:status` (see [Content pull](content-pull.md) and [Content push](content-push.md)) |

The Core client (`CoreClient`, `client.*`) needs no switch: it is config and a
container binding, and registers nothing.

A site switches a feature on in its own `config/core-web.php`. That file needs
only the keys it changes, since every top-level key it leaves out keeps the
package's default:

```php
<?php

return [
    'features' => ['snapshots' => true],
];
```

Publish the whole file (`--tag=core-web-config`) only to change more than
that. A published file overrides each top-level key whole, so one copied from
an earlier version still needs a `features` key of its own.
