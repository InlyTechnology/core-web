# Translation

`features.translation` lets a translation follow its original's page
builder. Statamic localizes a field whole: a translation either inherits the
original's replicator or overrides all of it, after which its blocks drift
from the original's with nothing to say so. A translation that follows its
original instead keeps the original's blocks and owns only their texts.

## What a following translation owns

Its replicators and grids (every top-level `replicator` and `grid` field,
with the replicators, grids and groups inside them) keep the original's:

- blocks and rows, and their order
- set types and the `enabled` switch
- every field that is not text: images, colours, links, selects, toggles

and own, per block and per row, the fields of a translated type:
`core-web.translation.translated`, by default `text`, `textarea`, `markdown`
and `bard`. A field overrides its type with `translate`:

```yaml
- handle: anchor_label
  field:
    type: text
    translate: false   # follows the original
```

Other top-level fields (a title, an SEO description) are localized the way
Statamic always does. A top-level Bard field is translated whole.

Blocks and rows are matched by the ids Statamic stores on them, so a block
the original moves keeps its translated texts. A block the original adds
arrives in the translation with the original's texts, a block it removes
leaves, and a block whose set type changes starts again from the original.

## Turning it on

```php
// config/core-web.php
return [
    'features' => ['translation' => true],
    'translation' => [
        'sites' => ['english' => 'synced'],
    ],
];
```

`sites` sets, per translation site, whether its translations follow their
original (`synced`) or keep their own blocks (`own`, Statamic's behaviour,
and the default for a site left out). Brand sites that share a CP but are
no translations of each other stay out.

Each translation shows a **Follow the original's blocks** toggle in the
sidebar of its CP page, its default the site's. Switched off, the page's
blocks are its own, as without the feature. Switched back on, the next save
takes the original's blocks again and keeps the texts of the blocks they
share.

## Keeping it aligned

- Saving an original carries its blocks and shared values to every
  translation that follows it, saving each that changed.
- Saving a translation that follows aligns it first, so a working copy
  started before the original changed publishes on the original's blocks.
- While a translation follows, its CP form refuses a save that adds,
  removes or moves a block, and says to switch the toggle off first. The
  same save may switch it off and change the blocks.
- A field the translation still inherits is left alone: it shows the
  original's blocks and texts until an editor translates something in it.

## Outdated and untranslated texts

A translation remembers, per text, which version of the original's text it
translates (under the hidden `core_web_translation` key). The toggle's
instructions say how many texts changed in the original since they were
translated, and how many are not translated yet: still the original's text,
or in a field the translation inherits. Translating a text again clears it.

With `checks` on, the `translation-sync` [content check](content-checks.md)
lists both per translation, and finds blocks that drifted in where no save
aligned them (an import, a file edited by hand). Fixing a drift writes a
draft on the original's blocks.
