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

Proposals are kept as JSON on a filesystem disk (`drafts.proposals`); a host
whose disk does not outlive a deploy names a bucket.
