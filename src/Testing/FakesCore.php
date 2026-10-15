<?php

namespace Inly\CoreWeb\Testing;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inly\CoreWeb\Content\CoreSigningKeys;
use Inly\CoreWeb\Ping\PingSignature;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A fake Core for a site's tests: the client pointed at `https://core.test`
 * with a token that always issues, Objects API answers stubbed over HTTP,
 * pings signed as Core signs them, and calls to the site's content
 * endpoints sealed with a test key it publishes as Core's. For a TestCase
 * (MakesHttpRequests).
 */
trait FakesCore
{
    protected string $fakeCoreHost = 'core.test';

    /** Whether answerRowMeta() honours `meta=compact`, as Core 9.0+ does, or ignores it, as an earlier Core does. */
    protected bool $fakeCoreCompactsMeta = true;

    /**
     * Points the client at a fake Core answering `$stubs` (Http::fake()
     * patterns, e.g. `core.test/api/v1/objects/facility*`), 404 for the rest.
     * A fresh fake each call: an earlier stub for a URL would otherwise keep answering.
     *
     * @param  array<string, mixed>  $stubs
     */
    protected function fakeCore(array $stubs = []): void
    {
        config([
            'core-web.client.base_url' => "https://{$this->fakeCoreHost}",
            'core-web.client.client_id' => 'web',
            'core-web.client.client_secret' => 'secret',
        ]);
        Cache::forget((string) config('core-web.client.token_cache_key'));
        CoreSigningKeys::fromConfig()->forget();

        Http::swap(new Factory);
        Http::fake([
            "{$this->fakeCoreHost}/oauth/token" => Http::response(['access_token' => 'token', 'expires_in' => 3600]),
            "{$this->fakeCoreHost}".CoreSigningKeys::PATH => Http::response(CoreEnvelopeSealer::test()->document()),
            ...$stubs,
            "{$this->fakeCoreHost}/*" => Http::response(['errors' => [['status' => '404']]], 404),
        ]);
    }

    /** Core stops answering. */
    protected function failCore(int $status = 503): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::response('down', $status)]);
    }

    /**
     * One page listing these resources, as the Objects API answers a list.
     *
     * @param  list<array<string, mixed>>  $resources
     * @param  list<array<string, mixed>>  $included
     */
    protected static function coreList(array $resources, array $included = []): mixed
    {
        return Http::response([
            'data' => array_values($resources),
            'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($resources)],
            ...($included === [] ? [] : ['included' => $included]),
        ]);
    }

    /**
     * A stub for `{type}/{id}` that answers the resource whose `id` the URL
     * names, and 404 for any other, as a ping's single read hits it.
     *
     * @param  list<array<string, mixed>>  $resources
     * @return \Closure(Request): mixed
     */
    protected static function coreObjectById(string $type, array $resources): \Closure
    {
        return function (Request $request) use ($type, $resources) {
            $id = (string) preg_replace('~^.*/'.preg_quote($type, '~').'/([^/?]+).*$~', '$1', $request->url());

            foreach ($resources as $resource) {
                if ((string) ($resource['id'] ?? '') === $id) {
                    return Http::response(['data' => $resource]);
                }
            }

            return Http::response(['errors' => [['status' => '404']]], 404);
        };
    }

    /**
     * From now on every Objects API answer is narrowed to the sparse
     * fieldsets its request names, as Core narrows it: `fields[_]` for the
     * listed or read objects, `fields[{table}]` for included ones, whose
     * table each `$tables` entry names by type (`['manufacturer' =>
     * 'manufacturers']`). A request naming no fieldset gets the whole
     * answer. Call it after fakeCore() (or any Http::fake()): a pull that
     * builds the same documents with it as without asks Core for every
     * field it reads.
     *
     * @param  array<string, string>  $tables  included type → its table
     */
    protected function answerSparseFieldsets(array $tables = []): void
    {
        Http::globalMiddleware(fn (callable $handler) => fn (RequestInterface $request, array $options) => $handler($request, $options)->then(
            fn (ResponseInterface $response) => str_contains($request->getUri()->getPath(), '/api/v1/objects/')
                ? self::sparseResponse($request, $response, $tables)
                : $response,
        ));
    }

    /**
     * The names a request's sparse fieldsets select, by table (`_` for the
     * objects read); none when it names no fieldset.
     *
     * @return array<string, list<string>>
     */
    protected static function requestedFieldsets(RequestInterface|Request $request): array
    {
        $uri = $request instanceof Request ? $request->url() : (string) $request->getUri();
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return array_map(
            fn ($names) => array_values(array_filter(array_map('trim', explode(',', (string) $names)), fn (string $name) => $name !== '')),
            is_array($query['fields'] ?? null) ? $query['fields'] : [],
        );
    }

    /** @param  array<string, string>  $tables */
    private static function sparseResponse(RequestInterface $request, ResponseInterface $response, array $tables): ResponseInterface
    {
        $fieldsets = self::requestedFieldsets($request);
        $body = json_decode((string) $response->getBody(), true);

        if ($fieldsets === [] || ! is_array($body)) {
            $response->getBody()->rewind();

            return $response;
        }

        $narrow = function (array $resource, ?array $fields): array {
            if ($fields !== null && is_array($resource['attributes'] ?? null)) {
                $resource['attributes'] = array_intersect_key($resource['attributes'], array_flip($fields));
            }

            return $resource;
        };

        if (is_array($body['data'] ?? null)) {
            $body['data'] = array_is_list($body['data'])
                ? array_map(fn (array $resource) => $narrow($resource, $fieldsets['_'] ?? null), $body['data'])
                : $narrow($body['data'], $fieldsets['_'] ?? null);
        }

        if (is_array($body['included'] ?? null)) {
            $body['included'] = array_map(fn (array $resource) => $narrow($resource, $fieldsets[$tables[$resource['type'] ?? ''] ?? ''] ?? null), $body['included']);
        }

        return $response->withBody(Utils::streamFor(json_encode($body)));
    }

    /**
     * From now on every Objects API row carries the meta Core answers: a read
     * asking for `meta=compact` gets the row's identity alone, any other read
     * the full meta besides it, a `meta.schema` entry per attribute and
     * `meta.object`. With `$fakeCoreCompactsMeta` false every read gets full
     * meta. Call it after fakeCore() (or any Http::fake()): a pull that builds
     * the same documents from both answers reads nothing compact leaves out.
     */
    protected function answerRowMeta(): void
    {
        Http::globalMiddleware(fn (callable $handler) => fn (RequestInterface $request, array $options) => $handler($request, $options)->then(
            fn (ResponseInterface $response) => str_contains($request->getUri()->getPath(), '/api/v1/objects/')
                ? $this->rowMetaResponse($request, $response)
                : $response,
        ));
    }

    /** The row meta a request asks for: `compact`, or `full` when it names none. */
    protected static function requestedRowMeta(RequestInterface|Request $request): string
    {
        $uri = $request instanceof Request ? $request->url() : (string) $request->getUri();
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return is_string($query['meta'] ?? null) ? $query['meta'] : 'full';
    }

    private function rowMetaResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $response->getBody(), true);
        $response->getBody()->rewind();

        if (! is_array($body)) {
            return $response;
        }

        $compact = $this->fakeCoreCompactsMeta && self::requestedRowMeta($request) === 'compact';

        $meta = function (mixed $resource) use ($compact): mixed {
            if (! is_array($resource)) {
                return $resource;
            }

            $identity = array_diff_key((array) ($resource['meta'] ?? []), array_flip(['schema', 'object', 'formatted']));

            $resource['meta'] = $compact ? $identity : [
                ...$identity,
                'schema' => array_map(fn (mixed $value) => [
                    'type' => get_debug_type($value),
                    'value' => $value,
                    'formatted' => is_scalar($value) ? (string) $value : null,
                ], (array) ($resource['attributes'] ?? [])),
                'object' => [
                    'id' => (string) ($resource['id'] ?? ''),
                    'lookup_key' => $resource['type'] ?? null,
                    ...$identity,
                ],
            ];

            return $resource;
        };

        if (is_array($body['data'] ?? null)) {
            $body['data'] = array_is_list($body['data']) ? array_map($meta, $body['data']) : $meta($body['data']);
        }

        if (is_array($body['included'] ?? null)) {
            $body['included'] = array_map($meta, $body['included']);
        }

        return $response->withBody(Utils::streamFor(json_encode($body)));
    }

    /** Sets the ping secret the site verifies with (and signs with here). */
    protected function withPingSecret(string $secret = 'core-web-test-secret'): static
    {
        config(['core-web.ping.secret' => $secret]);

        return $this;
    }

    /**
     * A ping as Core signs it.
     *
     * @param  array<string, mixed>  $data  type, id, action (default saved), public_id
     * @return array{payload: string, signature: string}
     */
    protected function signedPing(array $data): array
    {
        return app(PingSignature::class)->sign($data + ['action' => 'saved']);
    }

    /** @param  array<string, mixed>  $data */
    protected function receivePing(array $data): TestResponse
    {
        return $this->postJson(route((string) config('core-web.ping.name', 'api.core.objects')), $this->signedPing($data));
    }

    /**
     * The headers of a call sealed with the key fakeCore() publishes as Core's.
     *
     * @param  array<string, mixed>  $claims  replace the sealed claims of the same name; a null one is left out
     * @return array<string, string>
     */
    protected function coreEnvelopeHeaders(string $method, string $uri, string $purpose, ?string $body = null, array $claims = []): array
    {
        return CoreEnvelopeSealer::test()->requestHeaders($method, $uri, $purpose, $body, $claims);
    }

    /**
     * Calls one of the site's content endpoints as Core does: sealed for
     * `$purpose`, a JSON body sent as the exact bytes sealed.
     *
     * @param  array<string, mixed>|string|null  $body
     * @param  array<string, mixed>  $claims
     */
    protected function callSealed(string $method, string $uri, string $purpose, array|string|null $body = null, array $claims = []): TestResponse
    {
        $content = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $body;
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];

        foreach ($this->coreEnvelopeHeaders($method, $uri, $purpose, $content, $claims) as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call(strtoupper($method), $uri, [], [], [], $server, $content);
    }
}
