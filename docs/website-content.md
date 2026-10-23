# Website content

The site half of Core's Statamic connector API v1.1 (inly-core
`docs/learn/website-content/statamic-connector-api.md`): Core reads a site's
pages and writes drafts to it, every call sealed with Core's own Ed25519 key,
and the site pings Core when a page changes. core-web opens the envelopes and sends
the pings; the endpoints, their routes and their JSON are the site's own.

## Routes

With `features.content` on, the `core-web.envelope:<purpose>` middleware opens
the envelope before the controller runs:

```php
Route::middleware('throttle:120,1')->prefix('api/core/v1')->group(function () {
    Route::get('content', [ContentController::class, 'index'])->middleware('core-web.envelope:website.read');
    Route::post('drafts', [DraftsController::class, 'store'])->middleware('core-web.envelope:website.draft');
    Route::post('drafts/{id}/publish', [DraftsController::class, 'publish'])->middleware('core-web.envelope:website.publish');
});
```

Register the routes outside the `web` group, in a routes file of their own
rather than `routes/api.php`, whose `api` prefix would make the path
`/api/api/core/v1`:

```php
// bootstrap/app.php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    then: fn () => Route::group([], base_path('routes/core-api.php')),
)
```

Keep `TrimStrings` and `ConvertEmptyStringsToNull` off them. On Laravel 11 and
later both are global middleware, so exclude the paths there. The envelope
binds the raw query string and body either way, but the controller should
read what Core sent:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/core/v1/*')]);
    $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/core/v1/*')]);
})
```

The API has the site answer over https outside a local environment.
core-web does not check the scheme of a request it opens: the site's
endpoints rely on the web server (or the proxy in front of it) to serve them
over https only.

The middleware checks, in the API's order:

1. the headers;
2. Core's signature, under a key id from Core's key document;
3. the payload;
4. the audience, which is the site's `client.client_id`;
5. the expiry, which is at most six minutes ahead;
6. the purpose;
7. the method;
8. the raw path;
9. the canonical query;
10. the body's SHA-256;
11. the nonce, spent once per audience until the envelope expires.

A draft or publish must also carry the site's environment (`content.environment`).

Every answer that passes through it, the controller's included, carries
`Cache-Control: no-store` and `X-Statamic-Uncacheable`. The claims are on the
request: `VerifyCoreEnvelope::claims($request)`.

| Answer | When |
|---|---|
| 403 `{"error":"envelope_refused"}` | No envelope, a bad signature or an unreadable payload: nothing is said about why |
| 403 `{"error":"envelope_refused","reason":"…"}` | The signature verified and a later check failed: `audience`, `expired`, `purpose`, `method`, `path`, `query`, `body` or `replayed` |
| 403 `{"error":"wrong_environment","message":"…"}` | A draft or publish sealed for another environment |

Core's key document (`{core}/.well-known/core-signing-keys`) is read over
https only (plain http in `local` and `testing`), from the paired Core alone:
a redirect is not followed. A copy is refreshed once it is four minutes old:
one call takes the refresh and the rest keep opening with the copy, as they do
when the refresh fails, with a retry every 10 seconds. A copy five minutes
old opens nothing, whether or not Core can be read, so a key Core retires
stops opening envelopes within five minutes even while the fetch fails. A
site with no copy refuses until a fetch succeeds, and tries again after 10
seconds, so it picks Core up soon after Core comes back. An envelope naming a
key id a fresh copy lacks refetches at most once a minute, since the key id
is read before anything is verified.

An envelope that arrives with no request to bind it to, such as one handed
over in a URL, is opened with
`app(CoreEnvelope::class)->open($payload, $signature, $keyId, $purpose)`. It
checks steps 1 to 6 and spends the nonce. Use it only for a purpose whose
claims say everything the envelope allows. An envelope that carries a
`method`, `path`, `query` or `body_sha256` claim was sealed to a request and
is refused here with that reason, so a `website.*` call never opens without
the request it binds.

## Pings

`app(ContentPings::class)->send($pageId, 'saved'|'deleted')` posts
`{"type":"page","id":…,"action":…,"nonce":…}` to
`{core}/webhooks/connectors/{content.connector_id}` with the client token.
The nonce is new for every attempt. `enabled()` is false until the client and
the connector id are set. `send()` throws when Core does not accept the ping,
so call it from a queued job and let the queue retry.

Each pairing has a connector of its own in Core, so a staging site sets its
own `content.connector_id` (`CORE_CONTENT_CONNECTOR_ID`). One that keeps the
production site's id pings the production connector with its own client
token, and Core answers every ping with a 401.

## Testing

`fakeCore()` publishes a test key as Core's. `callSealed($method, $uri,
$purpose, $body, $claims)` calls a content route sealed with it, with a JSON
body sent as the exact bytes it sealed. `$claims` replaces sealed claims, and
a `null` value leaves a claim out. `coreEnvelopeHeaders()` gives the headers
alone, and `CoreEnvelopeSealer` seals with a key of your own.

```php
$this->fakeCore();
$this->callSealed('GET', '/api/core/v1/content?limit=10', 'website.read')->assertOk();
$this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', $body, ['environment' => 'staging'])
    ->assertForbidden()->assertJson(['error' => 'wrong_environment']);
```

core-web's own suite runs the API's test vectors
(`tests/fixtures/statamic-connector-api-v1-test-vectors.json`): those that
use Core's key as given, and the shared-secret ones re-sealed with the test
key, so their method, path, query and body refusals run against Core's key too.
