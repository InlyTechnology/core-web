<?php

namespace Inly\CoreWeb\Client;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Server-to-server client for an Inly Core install's API, authenticating with
 * a Passport client_credentials token (cached, fetched again on a 401).
 * Reads Core's Objects API as JSON:API; a site's snapshots pull through it.
 */
class CoreClient
{
    /**
     * The row meta object reads ask for unless the query names its own:
     * identity and display strings, without the per-row `meta.schema` and
     * `meta.object` (Core 9.0+). An older Core ignores the parameter and
     * answers whole rows; the documents read the same either way.
     */
    public const ROW_META = 'compact';

    protected string $baseUrl;

    public function __construct(
        string $baseUrl,
        protected string $clientId,
        protected string $clientSecret,
        protected string $tokenCacheKey = 'core_web_access_token',
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Built from core-web.client at the moment of the call, so it answers from the configuration of the moment. */
    public static function fromConfig(): static
    {
        return new static(
            (string) config('core-web.client.base_url'),
            (string) config('core-web.client.client_id'),
            (string) config('core-web.client.client_secret'),
            (string) (config('core-web.client.token_cache_key') ?: 'core_web_access_token'),
        );
    }

    /** Whether this site has been told how to reach Core at all. */
    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * One object from Core's Objects API as a JSON:API document (`data` +
     * optional `included`), or null when Core answers 404: the object is
     * gone, or this client may no longer read it.
     *
     * @param  array<string, mixed>  $query  extra parameters, e.g. include, or meta=full for the per-row schema
     * @return array<string, mixed>|null
     */
    public function object(string $type, int|string $id, array $query = []): ?array
    {
        $path = "/api/v1/objects/{$type}/{$id}";
        $response = $this->fetch($path, $query + ['meta' => static::ROW_META]);

        if ($response->status() === 404) {
            return null;
        }

        return $this->decode($response, $path);
    }

    /**
     * Every object of a type, page by page, each wrapped like a single
     * document so one reader handles both a ping and a full pull. Returns
     * (via getReturn()) the list's stated `total` and whether pages may be
     * missing: an answer without `last_page` is read as one page, which a
     * full page or a larger total contradicts.
     *
     * @param  array<string, mixed>  $query  extra parameters, e.g. filter[…] and include, or meta=full for the per-row schema
     * @return Generator<int, array<string, mixed>, mixed, array{total: ?int, truncated: bool}>
     */
    public function objects(string $type, int $pageSize = 50, array $query = []): Generator
    {
        $page = 1;
        $listed = 0;
        $total = null;
        $truncated = false;

        do {
            $response = $this->get("/api/v1/objects/{$type}", $query + ['meta' => static::ROW_META, 'page[number]' => $page, 'page[size]' => $pageSize]);
            $data = (array) ($response['data'] ?? []);

            foreach ($data as $resource) {
                $listed++;
                yield ['data' => $resource, 'included' => $response['included'] ?? []];
            }

            $meta = (array) ($response['meta'] ?? []);
            $total = isset($meta['total']) ? (int) $meta['total'] : $total;

            if (! isset($meta['last_page'])) {
                $truncated = count($data) >= $pageSize || ($total !== null && $total > $listed);

                break;
            }

            $last = (int) $meta['last_page'];
            $page++;
        } while ($page <= $last);

        return ['total' => $total, 'truncated' => $truncated];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->decode($this->fetch($path, $query), $path);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function post(string $path, array $data = []): array
    {
        $response = $this->send($path, $data, $this->token());

        // On 401 the cached token is likely expired: drop it and retry once.
        if ($response->status() === 401) {
            $this->forgetToken();
            $response = $this->send($path, $data, $this->token());
        }

        if ($response->failed()) {
            throw new RuntimeException("Core API error [{$response->status()}] on POST {$path}: ".$response->body());
        }

        return $response->json() ?? [];
    }

    /** A request to Core, trusting the configured CA bundle when there is one (a local self-signed certificate). */
    public static function request(): PendingRequest
    {
        $bundle = (string) config('core-web.client.ca_bundle');

        return $bundle !== '' ? Http::withOptions(['verify' => $bundle]) : Http::createPendingRequest();
    }

    public function forgetToken(): void
    {
        Cache::forget($this->tokenCacheKey);
    }

    /** @param  array<string, mixed>  $query */
    protected function fetch(string $path, array $query = []): Response
    {
        $attempt = fn (string $token) => $this->client($token)
            ->withHeaders(['Accept' => 'application/vnd.api+json'])
            ->retry(2, 500, throw: false)
            ->get($this->baseUrl.'/'.ltrim($path, '/'), $query);

        $response = $attempt($this->token());

        if ($response->status() === 401) {
            $this->forgetToken();
            $response = $attempt($this->token());
        }

        return $response;
    }

    /** @return array<string, mixed> */
    protected function decode(Response $response, string $path): array
    {
        if ($response->failed()) {
            throw new RuntimeException("Core API error [{$response->status()}] on GET {$path}");
        }

        return $response->json() ?? [];
    }

    /** @param  array<string, mixed>  $data */
    protected function send(string $path, array $data, string $token): Response
    {
        return $this->client($token)->post($this->baseUrl.'/'.ltrim($path, '/'), $data);
    }

    protected function client(string $token): PendingRequest
    {
        return $this->http()
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30);
    }

    protected function http(): PendingRequest
    {
        return static::request();
    }

    /**
     * A cached client_credentials access token, fetched on a miss. Issued
     * without explicit scopes: Core falls back to the client's own.
     */
    protected function token(): string
    {
        if ($cached = Cache::get($this->tokenCacheKey)) {
            return $cached;
        }

        $response = $this->http()
            ->asForm()
            ->connectTimeout(5)
            ->timeout(15)
            ->post($this->baseUrl.'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => '',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to obtain a Core access token: '.$response->body());
        }

        $token = (string) $response->json('access_token');
        $expiresIn = (int) $response->json('expires_in', 3600);

        // 60 seconds short of the expiry, so a token about to expire is never used.
        Cache::put($this->tokenCacheKey, $token, max(60, $expiresIn - 60));

        return $token;
    }
}
