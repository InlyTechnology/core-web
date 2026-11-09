# Object preview

Core shows a record's page on the site beside the record's edit form, with
the values the person has not saved yet (Statamic connector API v1.5 and
v1.6, [Core's docs](https://docs.inlycore.com/learn/website-content/website-preview)),
and frames one of the site's own pages as it is in the CMS (v1.7, [a page of
the site](#a-page-of-the-site)). `features.preview`, with
`features.content`, gives Core the frame of the site's pages; with
`features.snapshots` too, it also gives Core which records the site serves a
page for, and a route that renders one from Core's unsaved values.

## Naming the Core object

A snapshot whose documents have [pages](pages.md) names the Core object they
are:

```php
Snapshot::make('campaigns')
    ->pull(/* ... */)
    ->pullOne(fn (string $id) => /* the documents Core's record $id is */)
    ->pages(fn (array $doc, string $site) => '/kampanjer/'.$doc['slug'])
    ->coreObject('campaign', id: fn (array $doc) => $doc['core_id']);
```

- `coreObject($type, $id, $alias)` takes the object's lookup key in Core.
  `$id` gives Core's primary key when the document's id is not it, and
  `$alias` the object's name in Core's `core.website_pages.pick_types` when
  the Core project gives it one. `listing()`'s `$coreType` and `$coreId` name
  the same; a `listing()` without them keeps what `coreObject()` named,
  whichever is called first.
- The preview needs `pullOne()`: it is how the site reads the record from
  Core with the person's values.

## What Core reads

`GET /api/core/v1/object-pages` and `GET /api/core/v1/object-page?type&id`,
sealed for reading (with `features.snapshots` on), list each such document by Core's type and id with its
page on every site, read from the stored copy. While nothing at all can be
read (a cold cache and Core unreachable) they answer `503`, never an empty
list, so Core keeps its addresses.

## The preview route

Core frames `GET /core-preview/object?type&id&site[&state]` (the path is
`preview.path`, throttled by `preview.middleware`):

1. With `state`, the site calls the snapshot's `pullOne()` with Core's id,
   and every read of Core it makes carries `X-Core-Preview: <state>`. Core
   answers the record with the unsaved values when it applied the state
   (`X-Core-Preview-Applied: true`).
2. The site renders the page through its own route for the document's path,
   with the previewed document in place of the stored one for every read of
   that snapshot during the render (the record under its saved id is left
   out, should the unsaved values give it another), and nothing of it
   written. The static cache is neither read nor written, `@nocache` regions
   are kept for this request only, and Statamic's cache tags are off. A cache
   of the site's own in a view (`Cache::remember`) must not run while
   `PreviewContext::current()->isRendering()`, or it keeps the unsaved values.
   A page that answers with a redirect (a canonical slug) is not followed:
   the frame shows a `404`.
3. The answer is `no-store`, `noindex`, `no-referrer`, framed by Core's
   address alone (`frame-ancestors`, from `client.base_url`), and carries the
   preview script. Like any [preview](cookie-consent.md#previews), it asks
   no consent and loads no tracker.

Without `state`, or when Core did not apply it, the route renders only a
record with a public page, as that page shows it, and answers `404` for any
other. Code that reads Core outside `pullOne()` during the render (a related
snapshot, a live read) reads it as saved.

## Marking fields and blocks

A click on a marked element in the frame tells Core which field or block it
shows, and a field Core lets the person type into is edited in place:

```blade
<h1 @coreField('title', true)>{{ $campaign['title'] }}</h1>
<p @coreField('summary', true)>{{ $campaign['summary'] }}</p>
<figure @coreField('image')><x-core-img :src="$campaign['image']" /></figure>

@foreach ($blocks as $block)
    <section @coreBlock($loop->index)>...</section>
@endforeach
```

The field is the property's name in Core, and the block's index is its
position among the page's blocks, counted from 0. `true` marks a plain-text field
that can be typed into; Core decides which of them the person may type into.
The markers print only on a page rendered for the preview frame, so a public
or cached page carries none. A hint is drawn after the element, so an image
is marked on its `<figure>` or another wrapper rather than on the `<img>`.

## A page of the site

Core's preview of one of the site's own pages frames it as it is in the CMS:
its working copy when it has one, else the entry, unpublished or scheduled
too. The page itself is not framed, since the site keeps its pages to its own
origin (an `X-Frame-Options` header, Live Preview's `frame-ancestors`), so
Core asks the site for a link to frame:

1. `GET /api/core/v1/page-preview/{id}`, sealed for reading, answers
   `{"data": {"entry_id": "...", "url": "..."}}`: a link the site signs for 24
   hours, on the origin of the page's own site. An entry with no page answers
   `404` with the error `not_found`: one with no address, a redirect, or,
   where the site serves the stock content feed, one in a collection the feed
   does not serve.
2. Core frames the link, `GET /core-preview/page/{id}` with its signature
   (the path is `preview.page_path`, throttled by `preview.middleware`). The
   site renders the page through its own route: the page itself while it is
   live as it stands, else the working copy or the entry in Live Preview.
   The render and the answer are the preview route's (steps 2 and 3 above):
   nothing of it cached, `no-store`, framed by Core alone, with the preview
   script and the markers, and no consent asked.

Core asks for a fresh link each time its preview screen opens, so a link
outlives a screen left open for the day and no longer. Anyone holding it,
opened in a tab of its own too, sees the page as it is in the CMS until it
expires, as with a draft's preview link.

A working copy or an unpublished entry renders through Statamic's Live
Preview, which stands the previewed entry in for the stored one and does not
take it back. In a PHP process that ends with the request (PHP-FPM) nothing
outlives the frame; under a long-lived worker (Octane, a queue worker
rendering pages) the stand-in would reach the next request, so the frame
route is not served from one.
