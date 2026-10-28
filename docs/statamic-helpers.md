# Statamic helpers

Always loaded, whatever features are on: the field-shape plumbing every site
needs where Statamic has no single call for it, and the site's icon set
([below](#the-sites-icons)). They are namespaced classes,
never global functions; a site that wants a short name wraps one in its own
`app/helpers.php`.

| Call | What it does |
|---|---|
| `Values::unwrap($v)` | A `Value` or `ArrayableString` (a link field's `ArrayableLink` is one), however nested, down to what it holds. |
| `Values::collect($v)` | Any relation shape (a `Value`, `Collection`, query builder, list, single object, null) as a flat `Collection`, empty slots dropped. A query builder is fetched with `get()`, so an ordered one keeps the editor's manual order. |
| `Values::first($v)` | Its first item, in that order, or null. A URL string passes through. |
| `Assets::meta($asset, $key, $fallback)` | A meta key (`alt`, `focus`) of an asset or any field shape holding one, reporting and falling back instead of throwing: a meta cache miss copies the file out of the bucket, so a bare `->get()` turns a storage outage into a 500. |
| `Assets::mimeType($asset)` / `Assets::size($asset)` | The same guard; null when unknown. |
| `Links::url($v, $suffix)` | Any link shape (a `link` field, the Entry or Asset it unwraps to, a URL string) as a URL, with an editor's `?query` or `#anchor` suffix applied. |
| `Links::withSuffix($url, $suffix)` | Only the suffix: `filter=x`, `?filter=x` and `&filter=x` join the query string, `#section` sets the fragment. |

All live in `Inly\CoreWeb\Support`. Where Statamic has its own way, use it
instead: `Statamic::tag('mount_url:news')` or `Collection::find('news')->url()`
for a collection's URL, `$asset?->url()` for an asset's, and a global by its
handle in a view the cascade renders (`$configuration->field`, already
augmented; not inside a component or Livewire).

## The site's icons

`icons.folder` in `config/core-web.php` names a folder of the site's icons as
SVGs, registered as the Statamic icon set `site` whatever features are on.
Any icon field picks from it, each icon previewed:

```yaml
handle: icon
field:
  type: icon
  set: site
```

The stored value is the file's name (`arrow-right`), which the site draws
with its own icon library, as SVGs or as a font. For Font Awesome, name the
`svgs/<style>` folder of the copy committed with the site's font
(`base_path('public/fonts/fontawesome/svgs/light')`); the SVGs are then only
the picker's previews. A folder of just the icons the site uses keeps the
picker short.

Until `icons.folder` names a folder that exists on the server, `site` holds
Statamic's own icons, so a field naming it still opens (a named folder that
is missing is logged as a warning). Commit the folder, so every environment
has it.
