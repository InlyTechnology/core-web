# Reading Core

`CoreClient::objects($type, $pageSize, $query)` walks a listing page by page
and `object($type, $id, $query)` reads one record; `$query` carries the
Objects API parameters (`filter[…]`, `include`, `fields[…]`).

Both ask for **compact row meta** (`meta=compact`, Core 9.0+): each row keeps
`attributes`, `relationships`, `links` and its identity meta (`id`,
`lookup_key`, `title`, `created_at`, `updated_at`, `deleted`) plus
`meta.formatted`, the display string per field whose raw value does not carry
it. It leaves out the per-row `meta.schema` and `meta.object`, which repeat
every property's definition on every row and made up most of a listing. What
compact also drops is each field's per-row `data` beside its value (a money
field's currency, a related record's object data beyond its title); a
snapshot that reads those passes `'meta' => 'full'` in its query, which wins.
A Core before 9.0 ignores the parameter and answers full rows, so the same
pull reads the same documents from either. `get()` sends only what it is given.
