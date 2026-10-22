# Content push

`content:push` sends what a developer changed in `content/` back to the live
site, never live: an entry becomes a draft an editor publishes in the CP
(a working copy of a published entry, or a new unpublished entry), and a
global's values or a navigation tree becomes a proposal an editor applies
(see [Drafts](drafts.md) and [Proposals](drafts.md#proposals)). Nothing is published,
deleted or moved in a tree, and a push makes one proposal per global set or
nav and site. It uses the same login as [Content pull](content-pull.md) and
is on wherever `content_sync` is (`content_sync.push: false` on the live
site refuses it).

```bash
php artisan content:push            # every changed entry, global and nav tree
php artisan content:push --only=entries:pages,globals --site=default
php artisan content:push --dry-run  # what the site would do; writes nothing
php artisan content:push --partial  # push the files that pass, list the rest
php artisan content:push --force    # skip the "changed on the site since the pull" check
```

**What is sent.** Each file under `content/collections`, `content/globals`
or `content/navigation` whose hash differs from `content.lock` and from
what this checkout last pushed. Collection trees (the page order) and terms
are not pushed: they stay CP edits. An entry is pushed only into a
collection the drafts feature covers (`drafts.collections`), and a proposal
needs `features.drafts`. A new entry keeps the id the checkout gave it and,
in a structured collection, is placed under its local parent. At most
`content_sync.push_items` (200) files go in one push; narrow a larger one
with `--only` or `--site`.

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
files naming it are then refused.

**Permissions.** Pushing needs the **Push content** permission (under Core
Web) besides Pull content; a super user always may. Drafts and proposals
are written as the token's CMS user, as the drafts feature writes any
draft, with provenance `content-push` (who, which machine, the git commit)
and the message "Pushed by <name> from <machine>". A push never sets or clears an entry's
`author`. The command runs only
where `APP_ENV` is `local`.
