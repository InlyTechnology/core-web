# Drafts

`Inly\CoreWeb\Drafts\DraftWriter` writes drafts of the site's entries the
way an editor's CP save writes them, for whatever source makes them: Core AI,
a content push. It needs revisions on, and refuses a draft when they are off
rather than change a live entry.

- A new entry is saved unpublished; a published one gets a working copy and
  its live entry is left alone. A structured collection's new entry goes
  under its parent (`parent_id`) in the tree.
- Each draft is saved as its author's CP user, who must be allowed to make
  the change, with the author's provenance on the revision (`via` plus the
  source's own details) and its message. An editor's later CP save keeps the
  provenance.
- A draft never touches a working copy someone else holds, and the author's
  own must restate what it already changed. An update names the hash of the
  entry it was written against (`base_hash`), so it never undoes a later edit.
- Values are validated against the blueprint, values their field conditions
  hide are left out (`FieldConditions`, a port of the CP's), and `dry_run`
  checks without saving.
- `publish()` makes the draft live; `read()` returns it with who holds it.
  Both writes take the author or a closure that makes one, called only once
  the request names an entry it may draft, so a refusal about the request
  (an unknown entry is `not_found`, 404) comes before one about the author.

```php
$author = new DraftAuthor($cpUser, $name, DraftProvenance::make('content-push', ['by' => $email]), 'Pushed from local');

app(DraftWriter::class)->write($author, [
    'collection' => 'articles',
    'entry_id' => $id,            // omit for a new entry
    'fields' => ['title' => '…'],
    'base_hash' => $hash,         // the DraftBase hash read before the change
]);
```

The site names the collections sources may draft in `drafts.collections`
(see the config file). Two bindings shape a source's input:
`DraftValueNormaliser` turns the fields sent into stored values
(`StoredValues` keeps them as they are), and `DraftBase` is the hash an
update names (`FieldsHash` hashes the draft's fields). Refusals throw
`DraftRefused`, which renders as JSON (`error`, `message`, details); the
messages are translations a site rewords in
`lang/vendor/core-web/{locale}/drafts.php`.

## Dashboard widget

The `core_drafts` widget lists the drafts a source wrote that nobody has
published yet, newest first: every working copy that still carries a
provenance, and every unpublished entry whose latest revision is a source's
(an editor's own save of it makes it theirs, and it leaves the list). Each
row shows the entry's title, the source, the revision message (which names
the person Core AI drafted for), how long ago, and a Review link to the
entry in the CP. An editor sees only the entries they may view.

```php
// config/statamic/cp.php
'widgets' => [
    ['type' => 'core_drafts', 'via' => ['core-ai'], 'limit' => 5, 'width' => 50],
],
```

Options: `via` (the sources to show, every source by default), `limit` (5,
at most 50), `title` (`Drafts to review` by default, through `__()`), and
Statamic's own `width`, `can` and `sites`. It reads the revisions store
(the Stache or the Eloquent driver), and each unpublished entry's latest
revision, on every dashboard visit.

## Core AI's drafts (API v1.1)

With `features.content` on as well, core-web takes Core AI's drafts itself,
under `content.prefix` (`api/core/v1`), each sealed with Core's key:

| Route | Purpose |
|---|---|
| `GET blocks` (`?collection=` for one) | `website.read` |
| `GET blocks/{collection}/{handle}` | `website.read` |
| `POST drafts` (`dry_run` to validate only) | `website.draft` |
| `GET drafts/{id}` | `website.draft` |
| `POST drafts/{id}/publish` | `website.publish` |

A draft is the `DraftWriter` request above, written for the person the body
names in `actor` (as a fix or a proposal, see below), marked `via: core-ai`
with Core's request and approval ids. A new entry answers 201, an update or
a dry run 200. A site whose drafts arrive in another shape than Statamic
stores binds its own `DraftValueNormaliser`.

## Content schema

`blocks` is what Core AI builds a draft from: for every collection in
`drafts.collections`, its blueprints, their entry fields, and the blocks
editors build pages from (the sets of each blueprint's top-level
Replicators), each field with whether it is required, its options and
default, when it applies and how its value is stored, plus how often each
block is used and a few entries that use it. `blocks/{collection}/{handle}`
is one block in full, its nested fields included. Both carry
`catalog_hash`: a draft naming an older one is refused (`catalog_changed`,
with the current hash), so the AI never builds on blocks that changed under
it.

`Inly\CoreWeb\Drafts\StatamicContentSchema` reads it all from the
blueprints and fieldsets, so a new block is in it the moment its fieldset
is. What a site decides:

- **The block settings and the guide** (`drafts.schema`): the fieldset of
  the section chrome every block imports, listed apart as each block's
  `settings` (`block`), and the fieldset whose `guide_index`,
  `article_blocks` and `page_blocks` say where the editor guide starts and
  which blocks an article and a page are made of (`page_builder`).
- **Words for the AI**, in the blueprints: `instructions` and
  `ai_instructions` on a field, and `ai_instructions` and `guide`
  (`{path}#{anchor}` in the editor guide) in the one fieldset a block
  imports beside the settings. `ai_fillable: false` (a field the AI leaves
  to an editor) and `draft_validate` (rules only a draft is held to) are
  listed too, but only tell the AI: a site whose drafts must hold to them
  enforces them in its `DraftValueNormaliser`.
- **How a value is stored**, by fieldtype (`drafts.schema.value_formats`),
  over core-web's own: a site's own fieldtype, or its own way of taking an
  asset.
- **A Dictionary's keys**: a Dictionary whose stored keys cannot be guessed
  from its options implements `Inly\CoreWeb\Drafts\DescribesStoredValue`,
  and its `storedValue()` (`format`, `example`, anything else such as a Core
  `core_type`) is listed under the field.
- **The slug and date rules**, in core-web::drafts (`schema_slug_rules`,
  `schema_date_rules`), worded for the site's language in
  `lang/vendor/core-web/en/drafts.php`.
- **Anything else**: a subclass bound to `Inly\CoreWeb\Drafts\ContentSchema`,
  such as one whose `status()` shows a published entry the site hides by its
  own rules.

## Proposals

Content Statamic keeps no revisions of (a navigation tree, a collection's
page tree, a global's values, a term, an asset's meta) is changed by
proposal: a source stages the target's whole new value, and an editor
applies or discards it under **Content → Proposals** in the CP, which shows
what changes. The entry appears only while an editor has proposals to decide.

```php
$target = ProposalTargets::resolve('nav:main:default'); // tree:{collection}:{site}, global:{handle}:{site}, term:{taxonomy}:{slug}:{site}, asset:{container}::{path}

app(Proposals::class)->propose($author, $target, $newTree, Proposals::base($target));
```

- The proposer and the editor must both be allowed to edit the target.
- A proposal names the fingerprint of the value it was made from; it is
  refused when the target changed since, and never applied over a later
  change.
- One open proposal per target: its author's next one replaces it, anyone
  else's is refused until it is decided.
- Values are validated as the CP validates a save; a page tree proposal
  moves pages and never drops one.
- **Undo.** Applying records the value the proposal replaced, so it can be
  undone (`Proposals::undo()`, or **Undo** on the proposal and under
  *Recently applied* on the Proposals page) for as long as the target still
  holds what applying left: any later change, in the CP or by another
  proposal, ends it. The earlier value is restored exactly as it was
  stored, values the blueprint hides included. Undoing needs the same permission as applying. This is one step back for
  content without revisions, on any host (on Laravel Cloud, where git
  keeps no history of content, it is the only one); a proposal applied
  before this existed recorded nothing to undo to. The Proposals page stays
  at `core-web/proposals` under the CP (`/admin/core-web/proposals`) even
  when its nav entry is hidden.

Proposals are kept as JSON on a filesystem disk (`drafts.proposals`); a host
whose disk does not outlive a deploy names a bucket.
