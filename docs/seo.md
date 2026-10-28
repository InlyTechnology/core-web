# SEO

The `seo` feature replaces Peak SEO (studio1902/statamic-peak-seo) with the
parts a site renders on every page: the head tags, the Google tags held by
consent, `/robots.txt`, the sitemaps, the editors' redirects and the
page-not-found entry. The field handles are Peak SEO's, so a site moving off
it keeps its content.

Peak SEO and this feature cannot be installed together: Peak's routes
register after core-web's and would serve robots.txt and the sitemaps in
their place, so boot fails while both are there. Make the whole move in one
commit (see [Moving a site off Peak SEO](#moving-a-site-off-peak-seo)).

```php
// config/core-web.php
return [
    'features' => ['seo' => true],
    'seo' => [
        'social' => [
            'collections' => [
                'articles' => ['image' => 'image', 'description' => 'excerpt', 'type' => 'article'],
            ],
        ],
    ],
];
```

## The head

In the layout's `<head>`, after `<x-core-web::consent.head />` when consent is
on:

```blade
<x-core-web::seo.head :page="$page ?? null" />
```

It renders the title, the description, robots, the canonical, hreflang
links, Open Graph and Twitter tags, JSON-LD, Google site verification and the
Google tags. Without `page` it reads the cascade's. A page outside the
cascade (a controller's route) passes what it knows: `title`, `description`,
`image` (an asset or a URL), `url`, `type`, `noindex`. Markup of the page's
own (its structured data, say) goes in `@push('core-web.seo')`. What the
component is passed wins over the entry's fields.

- **Title**: the entry's `seo_title` as it is, else its title, the separator
  and the site name. A `change_page_title` row adds the collection's title or
  a text of its own, or replaces the title.
- **Description**: `seo_description`, else the collection's field
  (`social.collections`, or a `collection_defaults` row).
- **Robots**: only production is indexed, and only while the global's
  `noindex_production` toggle is off (each site has its own); every other environment is noindexed
  whatever it says. An entry or term is noindexed with its own `seo_noindex`.
  An error page (a 404, a 500) is always noindexed and has no canonical.
  Peak's `noindex_local`, `noindex_staging` and collection/taxonomy indexing
  defaults are not read.
- **Social card**: `og_image`, else the collection's image field, else the
  first page-builder block's when it is the hero (`social.hero`), else the
  global's `og_image`. With the `media` feature it is cropped on the CDN to
  `social.image`, around the focal point; without it the original file is
  linked as it is, so keep social images small.
- **hreflang** (`hreflang_auto`): the entry in each site it is published in,
  on sites sharing the `identifier` attribute.
- **JSON-LD**: the entry's `schema_jsonld`, and a `BreadcrumbList` when
  `breadcrumbs` is on. The site's Organization schema stays in its layout.

## Google tags

`tracker_type` is `gtag` (Google Analytics, `google_analytics`) or `gtm`
(Tag Manager, `google_tag_manager`), rendered only where
`trackers_{environment}` is on. With the `consent` feature the tags are held
until statistics is granted (Tag Manager through
`<x-core-web::consent.google-tag-manager>`), so the site's tracker test needs
no exception for them. Other tracking scripts are the site's own, held with
`@consentScript`.

## robots.txt

`/robots.txt` lists the global's Robots field, blocks the AI training
crawlers (GPTBot, ClaudeBot, Google-Extended, CCBot …), and names the sitemap
of every site on the host asked, the snapshot sitemaps included. The AI
search and assistant agents (OAI-SearchBot, PerplexityBot, Claude-User …)
stay allowed: `seo.robots.ai_search` false blocks them too, which takes the
site out of AI answers. Search crawlers are never blocked. `seo.robots.view`
names a view of the site's own; it gets `rules`, `training`, `search`,
`aiSearch` and `sitemaps`.

A route, not a file: remove a generated `public/robots.txt` and the deploy
step that writes it, or the web server serves that file instead.

## Sitemaps

`/sitemap.xml` at each site's address lists the published entries of
`sitemap_collections` and the terms of `sitemap_taxonomies` and
`sitemap_collection_taxonomies`, without what is noindexed, with
`sitemap_change_frequency` and `sitemap_priority` per entry and hreflang
alternates on a multisite. `/sitemaps.xml` indexes every site's sitemap and
the [snapshot sitemaps](sitemaps.md). `use_sitemap` off answers 404. The
list is cached (`seo.sitemap.cache_minutes`, an hour) until an entry, term,
collection, tree or global is saved. A scheduled entry saves nothing when its
date arrives, so it joins the sitemap within the hour.

## Redirects

The redirects global (`seo.redirects_global`, blueprint importing
`core-web::seo_redirects`) maps an old path to an entry or an external
address, 301 or 302. It is asked only when a front-end request would 404, so
a live page always wins. An exact path matches first (written encoded or
not), then a pattern between `#`s (`#^/blog/.*$#`); the target is fixed, a
captured group is not put into it. The query string is kept. A route of the
site's own that throws Statamic's `NotFoundHttpException` is redirected too.

## Page not found

`seo.not_found` names the global and entries field where editors pick the
404 page's entry (Peak's `configuration` / `error_404_entry`). Its fields are
handed to `errors/404` and the layout when they render a 404, so the page's
text and blocks are content. Remove a site's `ErrorPage::handle404AsEntry()`
and the Peak redirects partial in `errors/404`.

## Blueprints

The fieldsets are imported under the `core-web` namespace:

```yaml
# resources/blueprints/globals/seo.yaml
tabs:
  titles: { sections: [{ fields: [{ import: core-web::seo_titles }] }] }
  indexing: { sections: [{ fields: [{ import: core-web::seo_indexing }] }] }
  social: { sections: [{ fields: [{ import: core-web::seo_social }] }] }
  sitemap: { sections: [{ fields: [{ import: core-web::seo_sitemap }] }] }
  trackers: { sections: [{ fields: [{ import: core-web::seo_trackers }] }] }
```

`core-web::seo_redirects` goes on the redirects global, `core-web::seo_entry`
on each collection's blueprint (an SEO tab). Peak's knowledge graph, custom
scripts, server-side Tag Manager, other analytics vendors, consent banner and
generated social images have no fields here.

## Moving a site off Peak SEO

All in one commit, since the site boots with neither both nor half:

1. Switch `features.seo` on, move `config/social.php`'s rows to
   `seo.social.collections`.
2. Replace the Peak SEO snippet in the layout with
   `<x-core-web::seo.head :page="$page ?? null" />`, and drop the site's
   social-card class.
3. Point the global and collection blueprints at the `core-web::seo_*`
   fieldsets. The stored values carry over.
4. Remove the Peak redirects partial, `ErrorPage::handle404AsEntry()`, the
   robots override view, a generated `public/robots.txt` and the tests
   checking Peak's forked views.
5. `composer remove studio1902/statamic-peak-seo`, then check `/robots.txt`,
   `/sitemap.xml`, a redirect, a 404 and the head of a page of each
   collection.
