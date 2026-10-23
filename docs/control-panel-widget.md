# Control panel widget

A snapshot that declares `->listing()` can be shown on the control panel
dashboard by the `core_snapshot` widget: its documents, a page at a time, each
with a link to the record in Core. The widget only reads what the store already
holds (`SnapshotStore::cached()`), so a dashboard visit never waits on Core. A
snapshot with nothing cached yet shows "Loading" and queues its warm job
(one at a time per snapshot).

```php
Snapshot::make('staff')
    // ...
    ->listing(
        title: fn (array $doc, string $id) => $doc['name'],
        coreType: 'employee',                         // Core's lookup key; no link without it
        coreId: fn (array $doc, string $id) => $doc['core_id'] ?? null, // Core's primary key; defaults to the document id
    );
```

```php
// config/statamic/cp.php
'widgets' => [
    ['type' => 'core_snapshot', 'snapshot' => 'staff', 'per_page' => 10, 'width' => 50],
],
```

Options: `snapshot` (required), `title` (the type as a headline by default),
`icon` (a Statamic CP icon name, `link` by default), `per_page` (10, at most
100), `partition` (one partition; every partition by default), and Statamic's
own `width`, `can` and `sites`. Rows are sorted by
title in the selected site's language (with intl). Each snapshot and partition
pages on its own query parameter (`?core_{type}_page=2`,
`?core_{type}_{partition}_page=2`), so several widgets sit on one dashboard. A
badge says when the copy may be out of date or is a local `core:pull` copy. A
document the `title` closure fails on is listed by its id, unlinked, and logged.
Every label, the configured `title` included, goes through `__()`; core-web
ships Swedish in `lang/sv.json`, and a site adds its titles and any other
locale in its own `lang/<locale>.json`.

The link is Core's `/open/{object}/{id}` (`CoreClient::openUrl()`), which sends
a signed-in person to the record's page in Core, whatever its route; it needs
a Core release with that route (inly-core, `GET /open/{object}/{id}`); until
then the links answer 404. It opens in a new tab with the person's own Core session, so
CMS editors who sign in through Core are already signed in there. Store Core's
primary key in the document when the snapshot is keyed by something else (a
public id, say): the API answers with it as each resource's `id`. An id with a
`/` gets no link.
