# Content checks

Checks find what is wrong in the site's content that a machine can find,
and where the fix is mechanical, fix it as a draft or a proposal an editor
publishes. They run nightly (`checks.schedule`) or with
`php artisan content:check [check…] [--json]`; each check's latest findings
are kept (`checks.disk`, `checks.path`) for the `content_checks` dashboard
widget and for sources (Core AI) to read without running a check.

| Check | Finds | Fix |
|---|---|---|
| `own-url-links` | Links to the site's own pages typed as URLs, in entries and navigation trees: they break when a slug changes | A Bard link (keeping its query and anchor) or a `link` field becomes an entry reference, in a draft; a menu item picks the entry, in a proposal. Other fields are named for an editor |
| `dead-targets` | Entry and asset references to things that no longer exist, and menu items whose entry is gone | None: which page or file takes their place is an editor's call |
| `seo-gaps` | Published pages without a meta description, or with a title or description longer than a result shows (fields and lengths in `checks.seo`, Peak SEO's by default) | None generic; a source can draft the text |

```php
$checks = app(ContentChecks::class);
$checks->latest('own-url-links');                 // the last run's findings
$checks->fix('own-url-links', $findingId, $author); // a draft or a proposal
```

A finding's `id` is stable across runs. A fix finds the finding afresh
first, and fixes everything fixable in the same entry or tree at once. A site
adds its own checks (subclasses of `Inly\CoreWeb\Checks\Check`) to
`checks.checks`, or leaves one out.

## Core reads checks and proposes (API v1.2)

With `features.content` on as well, core-web registers the API's v1.2
endpoints itself, under `content.prefix` (`api/core/v1`), each sealed with
Core's key:

| Route | Purpose | With |
|---|---|---|
| `GET checks` | `website.read` | `checks` |
| `GET checks/{check}/findings?site=&subject=` | `website.read` | `checks` |
| `POST checks/{check}/fix` | `website.draft` | `checks` |
| `GET proposal-target?key=` | `website.read` | `drafts` |
| `POST proposals` (`dry_run` to validate only) | `website.draft` | `drafts` |
| `GET proposals/{id}` | `website.draft` | `drafts` |

A fix or a proposal is made for a person in Core: the body names them in
`actor` (`core_user_id`, `name`, `email`), with an optional `summary`,
`core_request_id` and `core_approval_id`. It is written as their CP account
(the one [CMS sign-in](cms-sign-in.md) stamped with their Core user id; a site that keeps
accounts another way binds its own `Inly\CoreWeb\Content\CoreUsers`), marked
`via: core-ai`, and refused with `no_cms_user` when they have none. Nothing
goes live: a fix is a draft, a proposal waits for an editor.
