# inly/core-web: rules for agents working on a site

This file ships with `inly/core-web` and changes with each release. A site imports it from its own
`CLAUDE.md` (`@vendor/inly/core-web/resources/agents/AGENTS.md`), so `composer update inly/core-web` brings the
rules that match the code. The API reference is the package docs (`vendor/inly/core-web/docs/`, one page per feature);
read a feature's page before wiring it. What the site itself decided (which features, which Core
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
- **Env is secrets and what differs per environment** (`CORE_CLIENT_SECRET`, `CORE_MEDIA_KEY`/`_SECRET`; the
  env table in `docs/configuration.md` has the rest, such as `CORE_CMS_SITE` and `CORE_CONTENT_CONNECTOR_ID` for a staging site
  paired with production Core). A value that is the same everywhere (the site's Core in `core.url` /
  `core.client_id`, the live site's `content_sync.remote_url`, the bucket, the CDN) is committed in
  `config/core-web.php`.
- **`CORE_URL` / `CORE_CLIENT_ID` are overrides, not setup.** A server leaves them unset and gets the committed
  `core`; only an environment paired with another Core (a staging Core) sets them, always both. A checkout runs
  with exactly `APP_ENV=local`: any other value gets the committed production Core. **A local checkout never
  points `CORE_URL` at a remote Core**: production data, the CP sign-in and forms come through the live site on
  `content:login`; `CORE_URL` locally names a local backoffice only. `core-web:doctor` fails on a remote one.
- **`php artisan core-web:doctor`** checks what the switched-on features need from the environment: the Core
  client, a real queue, the schedule. Run it after changing features or env, and on every server before launch.

## Core-owned data (`snapshots`)

- **Data Core owns is a snapshot, never content.** No importer, no upserter, no entries mirroring a Core object.
  An editor's own addition to a Core object (an intro, a photo for one page) lives on an entry that references
  the object by its Core id.
- **A wrong value is fixed in Core.** Core pings the site on every change and the next read has it.
- **Pings and offboarding notices need a real queue with a worker** wherever they arrive (`QUEUE_CONNECTION` other than `sync`), or a
  bulk ping's pull runs inside Core's request and is cut off.
- **`core:warm` runs nightly** (the package schedules it, so every server runs `schedule:run`) **and in the
  deploy**, right after `statamic:stache:refresh`, as `php artisan core:warm || true`.
- **Bump a snapshot's `version()`** whenever the document shape it stores changes, or the old shape is served
  until it expires.
- Snapshot sitemaps are listed in the robots view with `{{ core_sitemaps }}`.
- **Locally, data comes from the live site, not from production Core**: `content:pull` (with `content_sync`)
  brings the content and `core:pull` the snapshots, on one `content:login`. No developer needs a Core secret.

## Content pull and push (`content_sync`)

- **Local content changes reach the live site only through `content:push`**, as drafts and proposals an editor
  publishes in the CP, with the local media files they name. Never copy content to a server another way.
- **Pull before you change content**: `content:pull`, so the push knows which version you changed. A file changed
  on the site since the pull is refused (`base_changed`); pull again and redo the change, never `--force` over an
  editor's edit without the user's say.
- **Push exactly the files you changed, never everything.** The checkout may hold other people's unpushed edits,
  and a bare `content:push` sends all of them. An agent's push, in order:
  1. `php artisan content:status --json <paths>`: check that `push` lists exactly your files and nothing else.
  2. `php artisan content:push --json --dry-run <paths>`: every result's `status` is something other than
     `refused`; fix each refusal by its `error` and `message`.
  3. `php artisan content:push --json <paths>`: report each file's `status` and `cp_url` to the user, and that an
     editor makes it live in the CP.
  Name each file you changed as a path (`content/collections/pages/sv/om-oss.md`), or `git add` them and pass
  `--staged` instead. A folder or a quoted `*` pattern is for when the user asked for everything under it.
- **Always `--json` when an agent runs these commands**: one document, no prompts. `ok: false` with a message
  saying to run `content:login` means the person signs in; ask them, never work around it.
- **`--partial` and `--publish` only on the user's say.** `--partial` writes what passes while others are refused,
  and `--publish` makes the push live where the site sets `content_sync.push_publish`; never turn that on for a
  site without the user's say. Dry-run a `--publish` push first.

## CMS sign-in (`cms_sign_in`)

- **Core is the only way into the control panel.** Access is granted and revoked in Core; never hand-manage a
  Core-provisioned account's access in the CP. Map Core grants to Statamic roles in `cms_sign_in.roles`.
- **CP accounts have no password and are not committed**: keep `users/` out of git and out of
  `statamic.git.paths`. Without a password, Statamic confirms an elevated session (user and role screens) with
  an emailed code, so the site's mailer must work wherever editors sign in.
- **Locally**, the CP signs in through a local Core (`CORE_URL`); without one, through the live site on the
  machine's `content:login`, as a local account mirroring the live one's roles. Offline,
  `cms_sign_in.local_password_sign_in` restores Statamic's own login, only while `APP_ENV` is `local`.
- A site moving off its own SSO code removes that code in the same change (`docs/cms-sign-in.md`).

## Media (`media`)

- **Render pictures and videos with `<x-core-img>`, `<x-core-video>` or `<x-core-media>`**, given the Asset (or
  the field) rather than a URL, so the focal point and alt text come along. Never hand-build CDN query strings.
- **The asset disks are core-web's** (`media.disks`): remove a site-defined disk of the same name from
  `config/filesystems.php`. Outside production writes land in a layer and production's files cannot be deleted
  or moved; `CORE_MEDIA_LAYER=false` only on a server built before go-live, removed at launch.
- **The bucket key lives on servers only** (`CORE_MEDIA_KEY`/`_SECRET`, or Laravel Cloud's attached bucket).
  A checkout has none: with `content_sync` on, `php artisan content:pull --only=assets` (or a plain
  `content:pull`) brings the live site's asset index and `.meta` into `storage/core-web/remote-assets/`, and the
  CP and pages load the files from their public URLs. Never ask a developer for a bucket key, and remove a
  stale one from a local `.env`: any key there makes the disk try the bucket instead of the index.
- **Moving a site onto these disks** keeps its bucket paths: commit the bucket and CDN `url` in `media.bucket`
  (and `root` for any prefix above the folder), map the container's disk to the folder its files already sit in
  (`media.disks`), point the container's `disk:` at it, delete the old disk and its env vars from `.env.example`, and set
  `CORE_MEDIA_KEY`/`_SECRET` on every server before the deploy that switches.
- **A file uploaded locally stays local** (`public/media-layer/`, never committed) until `content:push` sends it
  with the content that names it, at the same path and never over another file. Content that names a local-only
  file and is not pushed renders a missing image in production.
- `php artisan media:check` checks the disks, the bucket's headers and a CDN rendition, and says on a checkout
  whether the asset index has been pulled.

## Content, drafts and checks (`content`, `drafts`, `checks`)

- **Nothing Core sends goes live by itself**: a fix is a draft, a proposal waits for an editor, and both are
  written as the person's own CP account.
- **Links to the site's own pages are entry references**, never typed URLs; `content:check own-url-links` finds
  the ones that are not; fixing a finding writes the draft.
- **Core AI's drafts are core-web's endpoints** (`drafts` + `content`): a site never keeps its own drafts controller
  or writer. What is site-specific goes in bindings: `DraftValueNormaliser` for its field shapes.
- **What Core AI builds a draft from is read from the blueprints** (`blocks`, `StatamicContentSchema`): never a
  hand-written block list. Guide the AI in the blueprints (`instructions`, `ai_instructions`, `guide`; `ai_fillable`
  and `draft_validate` only inform it unless the site's normaliser enforces them), a site fieldtype's stored shape in `drafts.schema.value_formats`, a Dictionary's keys with
  `DescribesStoredValue`; a `ContentSchema` subclass only for what none of these can say.
- A site's own checks subclass `Inly\CoreWeb\Checks\Check` and are listed in `checks.checks`.

## Forms, chat and live reads

- **A form renders Core's embed**; a `CoreForm` (Livewire, needs `snapshots`) only when the form needs the
  site's own markup. It still posts to the Core form and reads Core's definition: never copy the field list into
  the site. The component appends any required field the view leaves out.
- **An embed takes `CoreForms::embedBaseUrl()` as its Core address**, never `CORE_URL` read directly, so a local
  checkout without a Core relays it through the live site; place `<x-core-web::forms.relay-notice />` beside it,
  since a relayed entry is a real one.
- **Chat and live reads go through the site**, never from the browser to Core: the site's client token reads more
  than any page should show, so each live read is a named `LiveRead` and there is no pass-through.
- **A snapshot when data makes pages, a live read when it fills part of one** (a price, a quote, stock on a
  cached page).

## Cookie consent (`consent`)

- **Every script that sets an optional cookie is held back** with `@consentScript('<category>')` or
  `type="text/plain" data-consent`, and every embed that does sits in `<x-core-web::consent.gate>`. Google Tag
  Manager renders through `<x-core-web::consent.google-tag-manager>`, never through Peak SEO's tracker setting.
- **A new vendor is declared in the same change**: its key (or its own definition) in `consent.vendors`, and
  `consent.policy_version` raised so visitors are asked again. There is no scanner to catch a missed one.
- A site that must have a certified CMP (it shows Google's ads, or a contract names Cookiebot) keeps Cookiebot.

## Statamic helpers

- **Normalise a field's shape with `Inly\CoreWeb\Support\Values`**, an asset's meta with `Assets`, a link with
  `Links` (`docs/statamic-helpers.md`), never a copy of them in the site; a site's short global helper only wraps one.
- **Native Statamic first**: a tag, a modifier or the object's own method (`mount_url`, `->url()`, a global by its
  handle in a view) before a helper, and a new helper only where Statamic has no single call for it.

## Testing

- Use `Inly\CoreWeb\Testing\FakesCore` for a fake Core and signed pings, and
  `config(['core-web.snapshots.store' => null])` in the base `TestCase`. Tests never call a real Core.
