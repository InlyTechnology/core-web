# Live reads

Snapshots hold what every visitor sees the same way. A live read is for what
a snapshot cannot hold, or does not need to: a calculation on what the
visitor typed, a figure that must be current, a short list fetched and cached
on the server. The site names each read, and nothing else of Core is
reachable: there is no pass-through to Core's API, since the site's client
token reads more than any page should show.

Snapshot or live read? Data that makes pages of its own (a detail page, a
sitemap entry, schema) is a snapshot: Core's pings refresh it the moment it
changes, and it outlives an outage. Data that only fills part of a page is a
live read.

A read is a `LiveRead` class:

```php
use Inly\CoreWeb\Live\LiveRead;
use Inly\CoreWeb\Live\LiveRequest;

class LoanQuote extends LiveRead
{
    public function public(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['item' => ['required', 'alpha_dash', 'max:40'], 'months' => ['required', 'integer', 'min:1', 'max:120']];
    }

    public function request(array $input): ?LiveRequest
    {
        return LiveRequest::post('/api/app/items/'.rawurlencode($input['item']).'/quote', ['months' => (int) $input['months']]);
    }

    public function present(array $json, array $input): ?array
    {
        return ['monthly' => $json['data']['monthly'] ?? null];
    }

    public function ttl(): int
    {
        return 60;
    }
}
```

- `public()` opens the read to pages' scripts at `/!/core-web/live/{name}`.
  It is false by default: a read is for server code only until the site says
  otherwise.
- `rules()` validates the visitor's input; only the keys it names reach
  `request()` (a nested key needs a rule of its own, `x.*`). Query-string
  input arrives as strings, so cast. Bound every rule of a `public()` read
  (`max:`, `in:`, `between:`, `alpha_dash` for what goes in a path): each
  distinct input is a call to Core and an entry in the cache.
- `request()` is the one call to Core, on the site's client token (retried
  once on a 401). `rawurlencode()` every input the path carries: a path with
  a `.` or `..` segment, a raw `?` or `#`, a backslash, a space or a control
  character is refused (`unavailable`), since visitor input builds it. Return null when the site already knows there is nothing to
  read: the answer is `not_found` without calling Core.
- `present()` is what the page gets. The default is Core's `data`; return null
  for an answer the site cannot show (`unavailable`).
- `ttl()` keeps an answer per input, in `live.store`, and lets the visitor's
  browser keep it for what is left of that (`private, max-age`). 0, the
  default, keeps none. `keep($data)` returning false keeps that one answer
  nowhere: a fallback Core gave while a source was down, say.
- `staleFor()` serves the last good answer for that many seconds past
  `ttl()` while Core fails, instead of `unavailable` (`$result->stale`, and
  never kept by the browser; the JSON says `stale: true`). 0, the default,
  serves none.

After Core itself fails a read (502, 503, 504, a timeout, unreachable), the
read answers at once for `live.retry_after` seconds (30): its stale answer,
or `unavailable`, rather than every visitor waiting out the timeouts again. A kept answer has
the shape `present()` gave when it was kept: after a deploy that changes that
shape, the old one is served until its `ttl()` (and `staleFor()`) runs out.

A read answers the same for every visitor: never put anything personal in one.
On a statically cached page, what server code read is frozen into the page
until it is flushed, and live reads send no pings: a figure that must stay
current there belongs in a script calling a `public()` read.

Switch it on and name the reads:

```php
return [
    'features' => ['live' => true],
    'live' => ['reads' => ['loan-quote' => App\Live\LoanQuote::class]],
];
```

Every `live` key the site leaves out keeps its default, so a site's file
needs only `reads`.

The browser calls a public read at `GET /!/core-web/live/loan-quote?item=a1&months=24`
(under Statamic's action prefix, so never statically cached; `throttle:60,1`
by default); any other name is a 404. Server code, a Livewire component
included, skips the route and may call every read:

```php
$result = app(Inly\CoreWeb\Live\CoreLive::class)->read('loan-quote', ['item' => $id, 'months' => $months]);

$result->successful(); // $result->data, or $result->status and $result->errors
```

| Answer | Status | When |
|---|---|---|
| `{data: …}` | 200 | Core answered and `present()` returned the page's data; with `stale: true`, the last good answer while Core fails |
| `{error: "invalid", errors: {field: [...]}}` | 422 | The site's rules refused the input, or Core did (its messages passed on for the fields in `rules()` only) |
| `{error: "not_found"}` | 404 | Core answered 404, `request()` returned null, or the route was asked for a read that is not `public()` |
| `{error: "unavailable"}` | 503 | Core failed, timed out (`live.timeouts`), is not configured, or `present()` returned null; logged as a warning |
