# inly/core-web: rules for agents working on a site

This file ships with `inly/core-web` and changes with each release. A site imports it from its own
`CLAUDE.md` (`@vendor/inly/core-web/resources/agents/AGENTS.md`), so `composer update inly/core-web` brings the
rules that match the code. The API reference is the package README (`vendor/inly/core-web/README.md`);
read its section for a feature before wiring it. What the site itself decided (which features, which Core
types, which Core each environment pairs with) is in the site's own `CLAUDE.md` and `inly.yaml`.

## The package

- **Every feature is off until the site's `config/core-web.php` switches it on** (`features.*`), and a feature
  that is off registers nothing. The file holds only the keys the site changes; publish the whole config only
  to change more than that.
- **The package is changed in its own repository** (InlyTechnology/core-web), released as a tag and pulled in
  with `composer update inly/core-web`. Never a copy under `packages/`, never an edit in `vendor/`. A site
  needing something core-web almost does: propose the change to core-web.
- **Read `CHANGELOG.md`'s upgrade notes** for every version between the one the site had and the one it moves
  to, and apply each.
- **Env is secrets and what differs per environment** (`CORE_URL`, `CORE_CLIENT_ID`, `CORE_CLIENT_SECRET`,
  `CORE_MEDIA_KEY`/`_SECRET`, `CORE_REMOTE_CLIENT_SECRET` locally). A value that is the same everywhere (the
  remote Core's URL and client id, the bucket, the CDN) is committed in `config/core-web.php`.
- **`php artisan core-web:doctor`** checks what the switched-on features need from the environment: the Core
  client, a real queue, the schedule. Run it after changing features or env, and on every server before launch.

## Core-owned data (`snapshots`)

- **Data Core owns is a snapshot, never content.** No importer, no upserter, no entries mirroring a Core object.
  An editor's own addition to a Core object (an intro, a photo for one page) lives on an entry that references
  the object by its Core id.
- **A wrong value is fixed in Core.** Core pings the site on every change and the next read has it.
- **Pings need a real queue with a worker** wherever they arrive (`QUEUE_CONNECTION` other than `sync`), or a
  bulk ping's pull runs inside Core's request and is cut off.
- **`core:warm` runs nightly** (the package schedules it, so every server runs `schedule:run`) **and in the
  deploy**, right after `statamic:stache:refresh`, as `php artisan core:warm || true`.
- **Bump a snapshot's `version()`** whenever the document shape it stores changes, or the old shape is served
  until it expires.
- Snapshot sitemaps are listed in the robots view with `{{ core_sitemaps }}`.
- Locally, `php artisan core:pull` copies the remote Core's snapshots, so a machine without its own Core still
  renders real data.

## CMS sign-in (`cms_sign_in`)

- **Core is the only way into the control panel.** Access is granted and revoked in Core; never hand-manage a
  Core-provisioned account's access in the CP. Map Core grants to Statamic roles in `cms_sign_in.roles`.
- **CP accounts have no password and are not committed**: keep `users/` out of git and out of
  `statamic.git.paths`.
- **Locally**, a machine without `CORE_URL` signs in through the remote Core; without any Core,
  `cms_sign_in.local_password_sign_in` restores Statamic's own login, only while `APP_ENV` is `local`.
- A site moving off its own SSO code removes that code in the same change (README → CMS sign-in).

## Media (`media`)

- **Render pictures and videos with `<x-core-img>`, `<x-core-video>` or `<x-core-media>`**, given the Asset (or
  the field) rather than a URL, so the focal point and alt text come along. Never hand-build CDN query strings.
- **The asset disks are core-web's** (`media.disks`): remove a site-defined disk of the same name from
  `config/filesystems.php`. Outside production writes land in a layer and production's files cannot be deleted
  or moved; `CORE_MEDIA_LAYER=false` only on a server built before go-live, removed at launch.
- `php artisan media:check` checks the disks, the bucket's headers and a CDN rendition.

## Content, drafts and checks (`content`, `drafts`, `checks`)

- **Nothing Core sends goes live by itself**: a fix is a draft, a proposal waits for an editor, and both are
  written as the person's own CP account.
- **Links to the site's own pages are entry references**, never typed URLs; `content:check own-url-links` finds
  the ones that are not, and drafts the fix.
- A site's own checks subclass `Inly\CoreWeb\Checks\Check` and are listed in `checks.checks`.

## Forms, chat and live reads

- **A form renders Core's embed**; a `CoreForm` (Livewire, needs `snapshots`) only when the form needs the
  site's own markup. It still posts to the Core form and reads Core's definition: never copy the field list into
  the site. The component appends any required field the view leaves out.
- **Chat and live reads go through the site**, never from the browser to Core: the site's client token reads more
  than any page should show, so each live read is a named `LiveRead` and there is no pass-through.
- **A snapshot when data makes pages, a live read when it fills part of one** (a price, a quote, stock on a
  cached page).

## Testing

- Use `Inly\CoreWeb\Testing\FakesCore` for a fake Core and signed pings, and
  `config(['core-web.snapshots.store' => null])` in the base `TestCase`. Tests never call a real Core.
