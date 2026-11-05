# Content push

`content:push` sends what a developer changed in `content/` back to the live
site as changes an editor makes live: an entry becomes a draft an editor
publishes in the CP (a working copy of a published entry, or a new
unpublished entry), and a term's values, a global's values, a collection's
page tree or a navigation tree becomes a proposal an editor applies (see
[Drafts](drafts.md) and [Proposals](drafts.md#proposals)). A push never deletes
anything, makes one proposal per term, global set, tree and site, and
publishes only with `--publish` on a site that allows it (see
**Publishing** below).
It uses the same login as [Content pull](content-pull.md) and is on wherever
`content_sync` is (`content_sync.push: false` on the live site refuses it).

```bash
php artisan content:push            # every changed entry, term, global, page tree and nav tree
php artisan content:push --only=entries:pages,globals --site=default
php artisan content:push --dry-run  # what the site would do; writes nothing
php artisan content:push --partial  # push the files that pass, list the rest
php artisan content:push --force    # skip the "changed on the site since the pull" check
php artisan content:push --publish  # make it live, where the site allows it (below)
php artisan content:push content/collections/pages/sv/om-oss.md 'globals/*'  # only these files
php artisan content:push --staged   # only what is git add'ed (--committed: only what is committed)
php artisan content:status          # what a push would send, without calling the site
```

**Choosing what goes.** A push sends every changed file of the types and
site `--only` and `--site` leave, and three things narrow that further,
each also taken by `content:status`:

- **Paths**: each argument is a file or folder under `content/` (the
  `content/` prefix is optional) or a pattern where `*` matches across
  folders (quote it, so the shell does not expand it). Only the changed
  files they match are sent; the rest stay
  changed for the next push. A path that matches no file a push carries
  (a typo, a type `--only` leaves out, a file outside `content/`) refuses
  the push before anything is sent.
- **`--staged`**: only files whose contents are exactly as git's index has
  them, so `git add` is how a file is chosen. A file changed again after
  `git add` is left out.
- **`--committed`**: only files whose contents are exactly as the last
  commit has them. Pass one of the two: `--staged` compares with git's
  index, `--committed` with the last commit. Git compares through its own
  filters, so line-ending conversion is not a change.

**Status.** `content:status` lists what `content:push` with the same
arguments would send, from `content/` and `content.lock` alone: each file
as `new` (never pulled), `changed`, or `not_live` (pushed as it is, but
only `--publish` sends it again), whether it updates a draft the last push
left, and the local media files it names. It also lists the files pushed
and still waiting for an editor, which a plain push skips. It never calls
the site, so whether the site would take a file is what
`content:push --dry-run` answers. It exits non-zero when the selection is
more than one push carries.

**For agents.** `--json` on `content:push` and `content:status` prints one
JSON document and nothing else, and never asks anything: a push that needs
a login fails and says to run `content:login`. `content:status --json`
gives `ok`, `remote`, `push` (each file's `path`, `type`, `state`,
`draft_on_site` and `assets`), `waiting` and `limit`, with `ok: false`
and an `error` when the selection is over the limit or cannot be read. `content:push --json` gives `ok` (the exit code's meaning),
`remote`, `dry_run`, `files` (what was selected), `written`, `results`
(the site's answer per file: `status`, and for a refused file `error` and
`message`), `messages` (the text the command would have printed) and, when
the command itself failed, `error`.

**What is sent.** Each entry, term, global, collection page tree and
navigation tree file whose hash differs from `content.lock` and from what
this checkout last pushed, written in that order. An entry is pushed only
into a collection the drafts feature covers (`drafts.collections`), and a
proposal needs `features.drafts`. A new entry keeps the id the checkout
gave it and, in a structured collection, is placed under its local parent;
a page tree pushed in the same run may hold it, and moves it where the
checkout's tree has it once applied. A new entry needs a parent in its
tree, so the top level of a tree without a root page takes none. A page
tree never drops a page the site's tree holds (delete pages in the CP), its
home page stays first, and changing it needs permission to reorder the
collection. At most `content_sync.push_items` (200) files go in one push;
narrow a larger one with `--only` or `--site`.

**Page trees holding new entries.** The check, `--dry-run` included, runs
before those entries exist, so it checks the whole tree with their ids
allowed: where they sit (its depth, the home page), what it drops, the
permission and an open proposal by someone else are all checked there. As
the push writes, placing the new entries is not taken for a change on the
site, but any other change since the push began is (`base_changed`), so an
editor's reorder is never adopted. A tree refused after its new entries
were placed records the site's tree as its base, so pushing it again is
not refused as changed.

**Terms.** Statamic keeps no draft of a term, so a change to an existing
term is a proposal of its values on the taxonomy's first site, and a new
term is created, live, only by a push with `--publish` (a plain push
refuses it, `term_needs_publish`). A term's file also holds its values on
the other sites; a push leaves those alone and refuses a file that changes
them (`localized_term`) or its `blueprint:`. A new term's file must be
named by its slug, and the term is validated against its blueprint before
anything is written; a value its blueprint does not know or its field's
conditions hide is left out, and named under `ignored_fields` as an
entry's are. Changing a term needs the person's edit permission on its
taxonomy, creating one the create permission.

**Never undoing the site.** A pull records, per file, the site's hash of
the version it wrote (`bases` in `content.lock`). A push names it, and the
site refuses a file whose live version has changed since (`base_changed`),
so a push never reverts an editor's edit. An entry with an open working
copy this checkout did not push (even the developer's own, made in the CP)
is refused too (`working_copy`). `--force` skips both checks but never the
drafts' own guard: a working copy someone else holds is still refused.
Files pulled before this check existed have no base; pull again, or push
with `--force`, which says which files it cannot check. The whole push is
checked first and written only when every file passes; with `--partial`
the passing ones are written and the refused ones listed. Two files that
are the same entry refuse each other (`duplicate`). A refusal the check
could not foresee, the site changing between the check and the write,
still leaves the files before it written. Each refused file prints its
reason, and the command exits non-zero. One push carries at most
`content_sync.push_bytes` (10 MB) of content in all.

**The lock.** A push does not change what the lock says was pulled: the
draft is not live, so the next pull still sees the site's version. The
pushed hashes go under `pushed`, so an unchanged file is not sent twice,
and each entry's draft as the site hashed it after the push goes under
`drafts`, so pushing the same entry again goes over the checkout's own
draft rather than being taken for a change made on the site.

**Publishing.** `--publish` makes the push live on a site that allows it
(`content_sync.push_publish: true` on the live site; off by default, and
the site refuses a `--publish` push, dry run included, otherwise). Every
file is first written as above, and only then is each draft published and
each proposal applied, as the pushing person and within their own CP
permissions. What does not go live stays a draft or an open proposal and is
listed with the reason:

- an entry the person may not publish, or one whose file says
  `published: false`;
- an entry missing a field its collection is never published without
  (`drafts.collections.<collection>.published_with`), or failing the
  blueprint's validation. Other images the blueprint requires do not block
  a publish: the entry goes live and the push lists them as still needed;
- a proposal the person may not apply (a page tree needs the collection's
  reorder permission, a term its taxonomy's edit permission);
- everything, when a file is refused while writing (with `--partial`, the
  written files still go live).

A published entry gets a revision, so it is undone in the CP like any
other; an applied term, global or tree is undone from its proposal (see
[Proposals](drafts.md#proposals)), and a new term, created with no draft or
proposal before it, has nothing to undo to. When a file is
refused while writing, no new term is created either. A file that went live is recorded under `published` in the lock, with
its new site version in `bases`, so the next push of it needs no pull
first; a file left as a draft is not recorded as pushed, so the next
`--publish` sends it again (after a plain push, too). `--publish` cannot be
combined with `--force` (the site refuses it), and `--dry-run --publish`
checks the files but does not predict which entries would publish.

**Assets.** A file in the local media layer (see [Media → The disks](media.md#the-disks))
that a pushed file names by its path is uploaded to the same path on the
site first, with its alt text and focal point. It never overwrites: a path
the site already has with the same bytes is skipped, and one with other
bytes refuses the file that names it. Nothing is deleted. Uploading needs
the container's upload permission (and folder creation for a new folder),
the path's extension must be the file's own and one the CP takes, the
container's file rules apply as in the CP, and a file may be at most
`content_sync.push_asset_bytes` (20 MB). An uploaded asset is on the site
(and public, on a public container) as soon as it is stored, even when the
files naming it are then refused. For an entry, the check before the
upload counts the files the push is about to upload as there, so a required
image field naming one passes; a new term's required image field does not
get this yet.

**Permissions.** Pushing needs the **Push content** permission (under Core
Web) besides Pull content; a super user always may. Drafts and proposals
are written as the token's CMS user, as the drafts feature writes any
draft, with provenance `content-push` (who, which machine, the git commit)
and the message "Pushed by <name> from <machine>". A push never sets or clears an entry's
`author`. The command runs only
where `APP_ENV` is `local`.
