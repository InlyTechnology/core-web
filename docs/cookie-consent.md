# Cookie consent

A light consent tool in place of Cookiebot, for a site that needs no
certified CMP (IAB TCF, as Google requires of sites that show its ads). It
asks before any optional cookie is set, offers *Reject all* beside *Accept
all* in the same style, lets the visitor change their mind from any page,
re-asks after `renew_after_days` or when `policy_version` rises, and logs
each choice. It wears Inly Core's look unless the site overrides it.

What stays in the site: which vendors it loads, its cookie page and its
texts (the strings are `__()` keys, so the site's `lang/*.json` rewords
them, and editors can set the banner's own in the CP, below). It has no scanner: a vendor added to the site is added to
`consent.vendors` in the same change, and `policy_version` raised.

```php
// config/core-web.php
return [
    'features' => ['consent' => true],
    'consent' => [
        'vendors' => ['google_analytics', 'hubspot'],
        'policy_url' => '/cookies',
    ],
];
```

A vendor is a key of the catalogue in `resources/consent/vendors.json`
(Google Analytics and Ads, Meta Pixel, LinkedIn, HubSpot, Hotjar, Clarity,
YouTube, Vimeo), or a definition of the site's own:
`'booking' => ['name' => 'Booking', 'category' => 'marketing', 'cookies' => [['name' => 'bk_id', 'purpose' => 'Keeps the booking.', 'days' => 30]]]`.
A category no vendor uses is not asked for, and a site with only necessary
cookies shows no banner and no settings link at all, so Tag Manager and held
scripts need their vendors declared or they never load. The site's session, CSRF
and consent cookies are always declared as necessary.

## Editing the banner in the CP

`consent.global` names a global set (the site's Configuration global, say)
whose blueprint imports the `core-web::consent` fieldset:

```yaml
# resources/blueprints/globals/configuration.yaml
- import: core-web::consent
```

Editors then set the banner's title, its text and the cookie page (picked as
an entry, so it follows a slug change) per site. A field left empty keeps
the default text, or `policy_url`; a non-empty `policy-url` attribute on the
component still wins. A page with no version on the current site is not
linked there. Saving the global must flush the cached pages, as the `all`
invalidation rule (or `SiteScopedInvalidator`) does.

## The page

```blade
<head>
    <x-core-web::consent.head />          {{-- first: Consent Mode's denied defaults --}}
    <x-core-web::consent.google-tag-manager id="GTM-XXXX" />
</head>
<body>
    ...
    <footer><x-core-web::consent.settings-link /></footer>
    <x-core-web::consent.banner :policy-url="configPage('cookie_page')" />
</body>
```

```js
import coreConsent from '../../vendor/inly/core-web/resources/js/core-consent.js'
coreConsent()
```

Once the visitor has chosen, a round button in the bottom-left corner reopens
the settings, so taking consent back is as easy as giving it.
`consent.button` moves it (`bottom-right`) or turns it off (`false`) where the
site links `<x-core-web::consent.settings-link>` itself.

```css
@import '../../vendor/inly/core-web/resources/css/core-consent.css';
```

All of it is the same for every visitor, so it is safe in statically cached
pages: the choice lives in the `core_consent` cookie and the script applies
it.

- **Google Tag Manager**: render it through the component, not through Peak
  SEO's tracker setting. In `basic` mode (the default) the container waits
  until statistics or marketing is granted, and its tags read Consent Mode
  for which; `advanced` loads it at once and lets Google's tags
  send cookieless pings under Consent Mode. Tags in the container that need
  marketing consent read Consent Mode's `ad_*` signals, and a
  `consent_update` event is pushed to the dataLayer on every choice.
- **Any other script**: `@consentScript('marketing') ... @endconsentScript`,
  or `<script type="text/plain" data-consent="marketing" data-src="https://js.hs-scripts.com/123.js" async></script>`
  for an external one. `data-consent` takes several categories, space
  separated, all of which must be granted (`data-consent-match="any"` for
  any one of them).
- **An embed** (a HubSpot form, a booking frame, a video):
  `<x-core-web::consent.gate category="marketing">...</x-core-web::consent.gate>`
  shows a placeholder with *Accept marketing cookies* until it is granted.
  Declare the embed's vendor too, so the category is in the banner.
- **The declaration**: `<x-core-web::consent.declaration />` on the cookie
  page, a table per category read from the same list, with the visitor's
  current choice and a button to change it.

Every choice deletes the cookies of the categories it does not grant, and a
category taken back reloads the page, since a
script that ran cannot be stopped. `window.CoreConsent` has `open()`,
`has(category)`, `categories()` and `onChange(fn)`, and a
`core-consent:change` event fires on `window`.

## Several domains

On a multisite install every site shows the banner, in its own locale, and a
domain asks on its own: a cookie belongs to one domain, so a visitor moving
from one site to another chooses again there. `consent.sites.<handle>`
overrides any setting for one site (its vendors, branding, `policy_url`,
`policy_version`), and each log row names the site the choice was made on.

```php
'consent' => [
    'vendors' => ['google_analytics'],
    'sites' => ['trucks' => ['vendors' => ['google_analytics', 'linkedin_insight']]],
],
```

## Branding

The banner is Inly Core's: Paper, Ink and Core blue, Geist where the site
loads it, and a "Core Consent" line. `consent.branding` overrides
`accent`, `accent_foreground`, `background`, `foreground`, `font`, `logo` and
`show_mark`; the CSS custom properties (`--core-consent-accent`, ...) can be
set in the site's own CSS too.

## The consent log

```bash
php artisan vendor:publish --tag=core-web-consent-migration
php artisan migrate
```

Each choice posts to `/!/core-web/consent` (no session, rate limited per
address by `log.rate_limit`, refused from another origin) and becomes one
row: the visitor's random consent id (shown on the cookie declaration and under the dialog's consent details, so they
can quote it), the site, the action, the categories and the policy version. No IP
address, user agent or user. `consent:prune` deletes rows older than
`log.retention_days` (five years) on `log.prune_schedule`. A site that keeps
no log sets `log.enabled` to false.
