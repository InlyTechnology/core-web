# Statamic helpers

Always loaded, whatever features are on: the field-shape plumbing every site
needs where Statamic has no single call for it. They are namespaced classes,
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
