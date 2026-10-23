# Forms

For the rare form Core's embed cannot render: a step flow with photo slots,
price cards, anything that needs the site's own markup. The form is a
Livewire component with that markup, and it still posts to a Core form, so
the entries, the handlers and the admin's field list stay in Core. Every
other form renders Core's embed.

Needs Livewire (`livewire/livewire` ^3.6 or ^4.0, or the Statamic Livewire
addon) and `features.snapshots`. Switch on `features.forms` and name each
form class in `forms.classes`:

```php
// config/core-web.php
return [
    'features' => ['snapshots' => true, 'forms' => true],
    'forms' => ['classes' => [App\Livewire\DamageInspection::class]],
];
```

```php
use Inly\CoreWeb\Forms\CoreForm;

class DamageInspection extends CoreForm
{
    // The key of the Core form it posts to.
    public static function coreForm(): string
    {
        return 'damage-inspection';
    }

    // The Core fields the view renders.
    public static function renders(): array
    {
        return ['plate', 'name', 'email', 'photos', 'damage'];
    }

    protected function fieldsView(): string
    {
        return 'livewire.damage-inspection';
    }
}
```

The view holds the fields only, bound to `values.{field}`, and reads the
definition through `$coreForm` (`FormView`: `form()`, `options()`,
`visible()`); the component wraps it in the `<form>`, followed by any
required field the view leaves out, the form's privacy notice and a submit
button. What the definition carries does the rest:

- **Defaults**: `values` start with each field's default.
- **Missing required fields**: a required field the view does not render
  (`renders()` does not name it) is rendered after the view with default
  markup (`core-web::forms.field`), so an entry still passes Core's checks
  when an admin adds a required field. Conditions count: a field required
  only when another one has a value appears when it does. In `local` a
  warning above them names them. Place them yourself with
  `@include('core-web::forms.missing')`; `CoreForm::missingFrom()` finds
  them in a test.
- **Validation**: `submit` checks the visible fields against the
  definition (required, email, url, number, date, the offered options, file
  counts and sizes) before anything is sent; add rules with `extraRules()`.
  Conditions are read as Core reads them: a field a condition hid reads
  blank to the fields after it, and a choice with no option left is hidden.
- **Hidden fields** with a `source` are filled from the page the form was
  rendered on (`page.url`, `referrer`, `page.lang`, `query.{key}`);
  `pageTitle()` and `context()` supply `page.title` and `context.{key}`.
- **Sending**: the entry goes to `POST {core}/api/forms/{uuid}/submit` on
  the site's client token (files as multipart parts with neutral names),
  which Core takes for a form linked to the site's app client. Core's 422
  errors show on their fields. `$status` is then `sent`, `invalid`,
  `throttled` or `unavailable`; `sent()` runs after Core took an entry.
- **Bots**: Core's honeypot, Turnstile and nonce guard the embed, not an
  entry the site sends on its token, so the site guards its own forms: a
  honeypot field in the wrapper (a filled one is answered `sent` and goes
  nowhere), and `forms.submit_rate_limit` (5) entries per minute per address
  and form. The address is `request()->ip()`: behind a proxy, trust it
  (TrustProxies) or every visitor shares one limit. Add more (Turnstile, a
  question) in the view and `extraRules()` when a form draws spam.
- **Static cache**: the component captures the visitor's page (referrer,
  query string) when it renders, so on a statically cached page render it
  inside `@nocache`, or the first visitor's values go to everyone.
- **Language**: the definition is read in the app's locale when the
  snapshot is pulled, so labels and messages are in the site's default
  language.

Override `submitView()` for another button (null when the view has its own),
and put your own `resources/views/vendor/core-web/forms/*.blade.php` in
place of the default markup.

## Marking the form in Core

Core cannot tell that a site renders a form with its own markup, so tick
**Custom form embed** on the form's General tab in Core. Its Fields tab then
warns admins not to change the fields without asking a developer. A form
the site renders must also take server entries (`accepts_server`); the pull
logs a warning when it does not.

## Keeping up with Core

`tests/fixtures/core-form-definition-contract.json` is a copy of Core's
definition contract (docs/learn/forms/definition-contract.md in inly-core):
one schema that uses every field type and key, and the definition Core
serves for it. `FormDefinitionContractTest` checks the reader against it,
including that every key a field carries is one `FormDefinition::KEYS`
knows. When Core's contract changes, replace the copy and catch up on what
fails. A field type the reader does not know renders as a text input and is
logged.

## Forms on a local checkout

A local checkout without a Core (no `CORE_URL`) sends its Core forms
through the live site, on the machine's `content:login`, so they render and
submit locally with no Core secret. It needs `forms` and `content_sync` on
both sides and `APP_ENV=local` here; a server never relays.

- **Core's embed**: give it `CoreForms::embedBaseUrl()` as its Core address
  and load its script from under it (`/public/forms/embed/v1.js`). That is
  the site's Core when one is set, and otherwise the local relay, which
  forwards the embed's definition, nonce and submit calls to the live site.
  The live site sends them on to its Core as its own origin, so the form's
  origin allowlist needs nothing for local work. `CoreForms::isRelayed()`
  says which.
- **`CoreForm` classes**: their entries go to the live site, which sends them
  with its own client token.
- **Only the live site's own forms**: the live site relays a form only when
  it is in its form list (the `core-web-forms` snapshot), and lets one
  machine send 10 entries a minute.
- **Only this machine**: the local relay answers a loopback peer
  (127.0.0.0/8, `::1`) with no proxy or forwarding header (`X-Forwarded-For`,
  `Via`, …) and is a 404 to anything else, so a LAN visitor or a shared
  tunnel cannot send entries on the developer's login. A page served to
  anyone else gets no relay either: `embedBaseUrl()` is null there. A site
  run in Docker (Sail, DDEV) sees the bridge's address as the peer, so it is
  not covered: run the site on the host (Herd, `php artisan serve`) to use
  the relay.
- **Uploads**: one entry carries at most 10 MB of files through the relay
  (`core-web.forms.relay_max_upload_bytes`); more is a 413.

What a relayed form sends is a real entry. `<x-core-web::forms.relay-notice />`
says so above the form while forms are relayed, and renders nothing
otherwise; a `CoreForm` shows it by itself, an embed needs it placed beside
it. A form whose Core uses Turnstile cannot be relayed: Turnstile binds its
answer to the page's host.
