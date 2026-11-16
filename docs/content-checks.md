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
| `archived-links` | Links into web archives and search caches instead of the pages themselves, in entries, globals and navigation trees (hosts in [Archived links](#archived-links)) | When the archived address is one of the site's own pages, fixed as `own-url-links` fixes it, and a global's link in a proposal. Another site's page, an own path with no entry, or an address written in text is named for an editor, with the address the archive copied |
| `dead-targets` | Entry and asset references to things that no longer exist, and menu items whose entry is gone | None: which page or file takes their place is an editor's call |
| `seo-gaps` | Published pages without a meta description, or with a title or description longer than a result shows (fields and lengths in `checks.seo`, Peak SEO's by default) | None generic; a source can draft the text |

```php
$checks = app(ContentChecks::class);
$checks->latest('own-url-links');                 // the last run's findings
$checks->fix('own-url-links', $findingId, $author); // a draft or a proposal
```

A finding's `id` is stable across runs. A fix finds the finding afresh
first, and fixes everything fixable in the same entry, tree or global at
once. A site adds its own checks (subclasses of `Inly\CoreWeb\Checks\Check`)
to `checks.checks`, or leaves one out.

## Archived links

Content pasted from an archived copy of a page keeps the archive's links: a
site rebuilt from a Wayback Machine snapshot of its predecessor can serve
them for a year before anyone notices. A link added in the control panel
never passes through a site's CI, and a site that keeps its content in the
database (on Laravel Cloud) has no files to search, so `archived-links` reads
the stored content.

It looks for the Wayback Machine's snapshots under `/web/` on
`web.archive.org`, `wayback.archive.org` and `archive.org` (their other
pages, such as `/save/`, the front page or `/web/` itself, are the archive's
own), and for a value that is a snapshot path saved without its host
(`/web/20250512152946/https://…`), which 404s on the site; inside text only
full addresses are found. It also looks for `archive.today`, `archive.ph`,
`archive.is`, `archive.li`, `archive.vn`, `archive.md` (past their front
page and their own `submit`, `search` and `wip` pages),
`webcache.googleusercontent.com` and `cachedview.nl` (past their front page).
A leading `www.` is ignored, and so is punctuation that ends a sentence after
a link. A value that is one link with nothing around it
is that link; any other value is read as text, and each archive link written
in it is a finding of its own. Each finding's `details.original` is the
address the archive copied, read from the archive URL
(`https://web.archive.org/web/20250512152946if_/https://site.se/page/` is
`https://site.se/page/`), and null when the URL does not carry it (an
archive.today short link).

The address is matched to an entry as `own-url-links` matches a typed URL.
An archive keeps the scheme and host the page had then, so an address on one
of the site's hosts, with or without `www.` and on either scheme, is read
again on that site's own scheme and host: an archived
`http://www.site.se/page/` of `https://site.se` is fixed to the entry. A
Bard link whose visible text is the archive URL gives two findings: the
link, which can be fixed, and its text, for an editor. In a global, only a
value under one of the global's blueprint fields can be fixed, since a
proposal holds nothing else.

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
