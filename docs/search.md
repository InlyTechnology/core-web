# Search

A listing that visitors search and filter (vehicles in stock, products, open
positions) searches a Core object through `Inly\CoreWeb\Search\Search`. The
site writes no search of its own: no synonym list, no accent folding, no
filter rules and no API route. Core declares all of that on the object (its
`search` capability, `optionsFromValues()` on a property, `facets_available`),
and the site reads it from the object's `/schema` description.

Each search runs on one of two engines that give the same answer:

- **local**: over the object's [snapshot](registering-snapshots.md), in PHP,
  while the snapshot holds every row (at most `search.local_limit`, 5,000 by
  default) and the query keeps to the rules Core's search contract publishes.
  No row is fetched from Core while a visitor waits; only the object's
  description is, once every `schema_ttl`.
- **remote**: Core's Objects API (`GET /api/v1/objects/{object}` with
  `filter[...]`, `sort`, `page` and `facets`) on the site's client token, each
  answer kept briefly per query, the last one served while Core fails.

Core's contract (`docs/api/search-contract.md` in inly-core) pins the answers:
a set of rows, the declaration and what Core returns for each request. A copy
lives in `tests/fixtures/core-search-contract.json`, and the local engine is
tested against every case, so the two engines cannot drift apart unnoticed.

The backoffice runs Core v63.0.0 or later: earlier versions answer no facets
and do not mark the filters and sorts only Core runs.

## Switching it on

```php
// config/core-web.php
return [
    'features' => ['snapshots' => true, 'search' => true],
    'search' => [
        'objects' => [
            'vehicles' => ['object' => 'vehicle', 'snapshot' => 'vehicles'],
        ],
    ],
];
```

Each search is named by the site, with:

- `object`: the object's lookup key in Core.
- `snapshot`: the snapshot holding its rows, for the local engine. Leave it
  out to search Core only.
- `engine`: `local` or `remote` to always run on one, null (the default) to
  let the rows decide. `search.engine` sets it for every search.

The site's app client needs the object's read scope, and Core's `access()`
ladder for that client decides which rows either engine sees.

The snapshot is made with `Snapshot::fromObject()`, registered like any other
(`docs/registering-snapshots.md`), on Core's ping for the object:

```php
$this->app->extend(SnapshotRegistry::class, fn (SnapshotRegistry $registry) => $registry->register(
    Snapshot::make('vehicles')->fromObject('vehicle'),
    pings: ['vehicle'],
));
```

It pulls every row the client reads, each stored as the engines read a row
(`SearchRow`: the attributes by property name, with `id`), keyed by id, and
reads one again when Core pings it. A list Core gives only in part fails the
pull, so the last good copy stays. The snapshot must hold every row: a
filtered or partitioned one is not searched locally.

## Searching

```php
use Inly\CoreWeb\Search\Search;

$result = Search::for('vehicles')->search(request()->query(), facets: ['make', 'fuel'], pageSize: 24);

foreach ($result->rows as $row) {
    // $row['id'], $row['make'], $row['price'] …
}
```

`search()` reads the page address, keyed by the object's own filter keys:

| Parameter | Reads as |
|---|---|
| `q=elbil volvo` | `filter[_search]`: the words, and the filters its synonyms name |
| `make=Volvo,Škoda` | any of the values; a value holding a comma goes in a list, `make[]=Acme, Inc` |
| `price_min=200000`, `price_max=400000` | bounds, for a key in `filters_with_operators`: a number, or a date (`2026-01-05`, `2026-01-05T10:00`) |
| `gearbox=:empty` | rows with no value, for a key in `filters_nullable` |
| `sort=-price` | a sort in `sorts_available` |
| `page=2` | the page |

A key the object does not filter by, a sort it does not offer, a key
carrying any other token, a bound that is no number or date and a value none
of a key's declared options holds are dropped, and the page is at most
10,000, so a crafted address searches no differently from one the page
wrote. `query()` returns the `SearchQuery`
without running it, `run()` runs one, and `SearchQuery::toUrl()` writes a
query back as the page address that reads as it.

A `SearchResult` carries:

- `rows`, `total`, `page`, `pageSize`, `lastPage` and `ids()`;
- `facets` and `facet($key)`: per value, the rows selecting it would leave,
  with its label and count, most first, `:empty` last;
- `engine`: `local` or `remote`;
- `status`: `ok`; `stale` (the last good answer, while Core or the snapshot
  cannot give a fresh one); `unavailable` (no answer: Core failed and nothing
  was kept, or Core has never described the object); `invalid` (Core refused
  the query, or a search forced onto the local engine cannot run it there).
  `answered()` is true for `ok` and `stale`.

## Which engine answers

With no `engine` set, a query runs locally when all of these hold, and on
Core otherwise:

- the search names a registered, unpartitioned snapshot that is not degraded
  and holds at most `local_limit` rows (a copy found holding more is not read
  again until it changes);
- the object searches declared fields that are on the rows (`search.mode`
  `fields`), not an index or code of its own;
- every filter, including the ones a synonym names, follows a rule of the
  contract: yes/no, bounds, an exact value (options or values in use), part of
  a text, part of a plate. A field Core marks `filter_custom` (a filter only
  Core runs, or one on something the row does not carry) and a field missing
  from `fields_available` go to Core. A bound is a number on a number field
  or a date on a date field, and a value of a key with declared options is
  one of them;
- every sort is on the rows and ordered here as the database orders it: a
  number, yes/no or a date. Text, which the database orders by its
  collation, and a field Core marks `sort_custom` (an ordering of its own)
  go to Core;
- every facet is one counted by a value, at most 10.

A query with no sort takes the object's `default_sort`. Core's own default,
`created_at`, is not on the rows, so an object whose listings should run
locally declares a default sort that is (`->defaultSort('-published_at')`), or
the page sends one.

## Caching

- An object's description is kept `schema_ttl` seconds (900); an answer from
  Core `ttl` seconds per query (60). Either is served `stale_for` seconds
  longer (3,600) while Core cannot give a fresh one.
- After Core fails (a timeout, a refused token, 429 or 5xx), it is not asked
  again for `retry_after` seconds (30), or for its `Retry-After`, at most five
  minutes: the search answers at once with the last answer kept. An answer
  Core gives in a shape the engines cannot read counts as a failure, and a
  description in one is never kept.
- `timeouts` bounds each search request. The client token is fetched on its
  own when the cached one has expired, with the client's 15-second limit.
- A local search reads the snapshot, so it is as fresh as Core's last ping.
