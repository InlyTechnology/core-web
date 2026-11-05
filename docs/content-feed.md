# Content feed

The content feed is the site's half of the Statamic connector API's `content` endpoints (inly-core
`docs/learn/website-content/statamic-connector-api.md` § The content feed). Core reads every page the site serves
from it and keeps a copy of each one, and the site pings Core when a page changes. Core's stock Statamic importer
reads it, so a site that turns it on writes no feed, projection or ping job of its own.

## Turning it on

The feed needs `features.content`, and is switched on in `content.feed`:

```php
// config/core-web.php
return [
    'features' => ['content' => true, 'drafts' => true],
    'content' => [
        'feed' => [
            'enabled' => true,
            'dictionaries' => ['core_staff' => 'employee'],
        ],
    ],
];
```

| Key | Value |
| --- | --- |
| `enabled` | Serves `GET api/core/v1/content` and `GET api/core/v1/content/{id}`, sealed for `website.read`, and pings Core on every save and delete of a served page |
| `collections` | The collections served. When it is empty, the feed serves the collections in `drafts.collections` |
| `dictionaries` | For each Dictionary handle of the site's own that picks Core objects, the object's lookup key in Core |

A site that already serves a feed of its own leaves `enabled` off until it removes that feed. Otherwise both send pings.

## Paging

`GET api/core/v1/content` returns `{data, next_cursor}`, oldest change first, then by id compared byte by byte.

| Parameter | Value |
| --- | --- |
| `updated_since` | ISO 8601. Only pages changed at or after it |
| `cursor` | The `next_cursor` of the previous page; the next page starts strictly after it. Anything else is refused with 422 |
| `limit` | 1 to 200, default 100 |

`next_cursor` is null on the last page. A page saved between two reads moves to the end, so a read that follows the
cursor still sees it. `GET api/core/v1/content/{id}` returns `{data}`, or 404 with `error: not_found` for a page the
feed does not serve.

## What a page projects

Each page is one projection per locale. It carries the protocol's keys: `id`, `url`, `title`, `status`, `site`,
`text`, `updated_at`, `has_draft`, `shows`, `seo` and `content_hash`. It also carries these CMS-layer keys:

| Key | Value |
| --- | --- |
| `collection`, `blueprint`, `slug` | The entry's |
| `published`, `date` | The published flag, and the entry's date in ISO 8601 when it has one |
| `updated_by` | The name of whoever saved it last |
| `working_copy` | `{by, since, message, via}` while the entry has a working copy, where `via` is `core-ai` for a Core AI draft; null otherwise |
| `blocks` | `[{type, count}]`: the sets the page is built from, nested sets included. A disabled set is left out |
| `fields` | The stored values a draft builds on: the working copy's when there is one |

The projection reads the stored values against the blueprint and is never augmented, so it costs no snapshot or
asset read.

- **`text`** is the text a visitor reads, as plain text with whitespace collapsed, up to 10,000 characters. It comes
  from text, textarea, list and markdown fields and from Bard. It leaves out the title, the `seo` tab, a text field
  that holds an address, and an icon picked by name.
- **`shows`** lists every pick of a Dictionary backed by Core. A `core_snapshot_pages` Dictionary names its object
  type through its snapshot's listing (`Snapshot::listing($title, coreType: 'manufacturer')`). A Dictionary of the
  site's own names it through `dictionaries`. A pick stored as `{key}|{id}` gives its id after the last `|`.
  `field` is the pick's path, with set and row indexes, such as `page_builder.0.brand`.
- **`seo`** reads Peak SEO's `seo_title`, `seo_description`, `seo_noindex`, `og_title` and `og_description`. The
  canonical address and the social image are null.
- **`status`** is Statamic's own status. A site that hides pages by rules of its own, such as a page open only
  between two dates, binds `Inly\CoreWeb\Content\Feed\PageStatus` to its own class. A scheduled page going live
  pings Core; any other status change without a save is caught by Core's nightly full read.

`content_hash` is SHA-256 over the projection without `updated_at`, `has_draft` and `content_hash`, so Core skips a
write that changes nothing.

## Pings

Saving or deleting a served page, saving or discarding its working copy, and a scheduled page going live each queue
`PingPageJob`, after the save commits. A ping that cannot be queued is reported and never fails the save. It holds one job per page and action,
so a burst of saves sends one ping. Pings go to the connector named in `content.connector_id`, using the site's
client token ([Website content](website-content.md)). Core reads the page back. A site without a queue worker sends
each ping as part of the save.
