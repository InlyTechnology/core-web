<?php

namespace Inly\CoreWeb\Content;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * Opens an envelope Core sealed for this site with its own Ed25519 key, as
 * the website content protocol v1.1 specifies (Opening, steps 1 to 11, and
 * What a site checks). The site's app client id is the audience; every call
 * names the purpose its endpoint takes, and a draft or publish must also come
 * from a Core in the site's own environment.
 */
class CoreEnvelope
{
    public const PAYLOAD_HEADER = 'X-Core-Payload';

    public const SIGNATURE_HEADER = 'X-Core-Signature';

    public const KEY_ID_HEADER = 'X-Core-Key-Id';

    public const TTL_SECONDS = 300;

    public const SKEW_SECONDS = 60;

    public const READ = 'website.read';

    public const DRAFT = 'website.draft';

    public const PUBLISH = 'website.publish';

    /** The purposes that write, which a Core in another environment never sends. */
    public const ENVIRONMENT_PURPOSES = [self::DRAFT, self::PUBLISH];

    private const BODYLESS_METHODS = ['GET', 'HEAD'];

    public function __construct(
        protected CoreSigningKeys $keys,
        protected string $audience,
        protected string $environment,
        protected Repository $nonces,
    ) {}

    public static function fromConfig(): static
    {
        return new static(
            CoreSigningKeys::fromConfig(),
            (string) config('core-web.client.client_id'),
            (string) (config('core-web.content.environment') ?: app()->environment()),
            Cache::store(config('core-web.content.store')),
        );
    }

    public function audience(): string
    {
        return $this->audience;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    /** The claims that bind an envelope to a request, each refused as that part of the request when opened without one. */
    private const REQUEST_CLAIMS = [
        'method' => EnvelopeRefused::METHOD,
        'path' => EnvelopeRefused::PATH,
        'query' => EnvelopeRefused::QUERY,
        'body_sha256' => EnvelopeRefused::BODY,
    ];

    /**
     * The claims of an envelope bound to no request, such as one handed over
     * in a URL: the signature, payload, audience, expiry and purpose are
     * checked, then the nonce is spent. It suits only a purpose whose claims
     * say all it does, so an envelope sealed to a request is refused here: it
     * opens only with that request, through `openRequest()`.
     *
     * @return array<string, mixed>
     *
     * @throws EnvelopeRefused|WrongEnvironment
     */
    public function open(?string $payload, ?string $signature, ?string $keyId, string $purpose, ?string $audience = null): array
    {
        $audience ??= $this->audience;
        $claims = $this->verify($payload, $signature, $keyId, $purpose, $audience);

        foreach (self::REQUEST_CLAIMS as $claim => $reason) {
            if (array_key_exists($claim, $claims)) {
                throw new EnvelopeRefused($reason);
            }
        }

        return $this->spend($claims, $purpose, $audience);
    }

    /**
     * The claims of a request Core sealed for this endpoint's purpose, read as
     * received: the method it is routed as, the raw request target and the
     * exact body.
     *
     * @return array<string, mixed>
     *
     * @throws EnvelopeRefused|WrongEnvironment
     */
    public function openRequest(Request $request, string $purpose): array
    {
        return $this->openTarget(
            // The method routing dispatches on, so an override header cannot send a sealed POST to a DELETE route.
            $request->getMethod(),
            self::requestTarget($request),
            (string) $request->getContent(),
            $request->headers->get(self::PAYLOAD_HEADER),
            $request->headers->get(self::SIGNATURE_HEADER),
            $request->headers->get(self::KEY_ID_HEADER),
            $purpose,
        );
    }

    /**
     * The claims of a request given by its parts: `$target` is the raw request
     * target (`/path?query`, still percent-encoded) and `$body` the exact bytes.
     *
     * @return array<string, mixed>
     *
     * @throws EnvelopeRefused|WrongEnvironment
     */
    public function openTarget(string $method, string $target, string $body, ?string $payload, ?string $signature, ?string $keyId, string $purpose): array
    {
        $method = strtoupper($method);
        $claims = $this->verify($payload, $signature, $keyId, $purpose, $this->audience);
        [$path, $queryString] = self::splitTarget($target);

        if (($claims['method'] ?? null) !== $method) {
            throw new EnvelopeRefused(EnvelopeRefused::METHOD);
        }

        if (($claims['path'] ?? null) !== $path) {
            throw new EnvelopeRefused(EnvelopeRefused::PATH);
        }

        // The raw query string, which no middleware has trimmed or turned into nulls.
        parse_str($queryString, $query);

        if (! is_array($claims['query'] ?? null) || self::canonicalQuery($claims['query']) !== self::canonicalQuery($query)) {
            throw new EnvelopeRefused(EnvelopeRefused::QUERY);
        }

        if (! self::bodyBound($claims, $method, $body)) {
            throw new EnvelopeRefused(EnvelopeRefused::BODY);
        }

        return $this->spend($claims, $purpose, $this->audience);
    }

    /**
     * A query as the envelope binds it: nulls and empty arrays dropped,
     * booleans `1` or `0`, every other scalar a string, keys sorted as byte
     * strings at every level.
     *
     * @param  array<array-key, mixed>  $query
     * @return array<array-key, mixed>
     */
    public static function canonicalQuery(array $query): array
    {
        $canonical = [];

        foreach ($query as $key => $value) {
            $value = match (true) {
                $value === null => null,
                is_bool($value) => $value ? '1' : '0',
                is_array($value) => self::canonicalQuery($value),
                is_scalar($value) => (string) $value,
                default => throw new InvalidArgumentException('A query value is a scalar, an array or null, not '.get_debug_type($value).'.'),
            };

            if ($value !== null && $value !== []) {
                $canonical[(string) $key] = $value;
            }
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    /**
     * A request target's path and query string, split at the first `?` rather
     * than parsed: a URL parser reads `//host/…` as a host and gives up on a
     * colon in a segment (`/content/page:42`).
     *
     * @return array{0: string, 1: string}
     */
    public static function splitTarget(string $target): array
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://[^/?\#]*#i', $target, $origin) === 1) {
            $target = substr($target, strlen($origin[0]));
        }

        [$path, $query] = array_pad(explode('?', explode('#', $target, 2)[0], 2), 2, '');

        return [str_starts_with($path, '/') ? $path : '/'.$path, $query];
    }

    /** The request target exactly as received, never the decoded or routed path. */
    public static function requestTarget(Request $request): string
    {
        $target = $request->server->get('REQUEST_URI');

        return is_string($target) && $target !== '' ? $target : $request->getRequestUri();
    }

    /**
     * Steps 1 to 6: the headers, Core's signature, the payload, the audience,
     * the expiry and the purpose.
     *
     * @return array<string, mixed>
     *
     * @throws EnvelopeRefused
     */
    protected function verify(?string $payload, ?string $signature, ?string $keyId, string $purpose, string $audience): array
    {
        if ($purpose === '') {
            throw new InvalidArgumentException('A Core envelope is opened for the purpose its endpoint takes.');
        }

        if ($payload === null || $payload === '' || $signature === null || $signature === '') {
            throw new EnvelopeRefused(EnvelopeRefused::MISSING);
        }

        // A site with no client id is paired with no Core, so nothing it receives can be Core's.
        if ($audience === '' || $keyId === null || ! $this->keys->verifies($keyId, $payload, $signature)) {
            throw new EnvelopeRefused(EnvelopeRefused::SIGNATURE);
        }

        $claims = self::decode($payload);

        if ($claims === null || ! is_int($claims['expires_at'] ?? null) || ! is_string($claims['nonce'] ?? null) || preg_match('/^[0-9a-f]{32}$/D', $claims['nonce']) !== 1) {
            throw new EnvelopeRefused(EnvelopeRefused::PAYLOAD);
        }

        if (($claims['aud'] ?? null) !== $audience) {
            throw new EnvelopeRefused(EnvelopeRefused::AUDIENCE);
        }

        $now = now()->getTimestamp();

        // Bounded above too, so a sealer cannot hold its nonce in the ledger for longer than the window.
        if ($claims['expires_at'] <= $now || $claims['expires_at'] > $now + self::TTL_SECONDS + self::SKEW_SECONDS) {
            throw new EnvelopeRefused(EnvelopeRefused::EXPIRED);
        }

        if (($claims['purpose'] ?? null) !== $purpose) {
            throw new EnvelopeRefused(EnvelopeRefused::PURPOSE);
        }

        return $claims;
    }

    /**
     * Step 11, the nonce, spent last so a refused request spends none; then
     * the environment of a call that writes, refused once the envelope opened.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     *
     * @throws EnvelopeRefused|WrongEnvironment
     */
    protected function spend(array $claims, string $purpose, string $audience): array
    {
        $key = 'core-web:envelope:'.hash('sha256', $audience).':'.$claims['nonce'];

        if (! $this->nonces->add($key, true, max(1, $claims['expires_at'] - now()->getTimestamp()))) {
            throw new EnvelopeRefused(EnvelopeRefused::REPLAYED);
        }

        if (in_array($purpose, self::ENVIRONMENT_PURPOSES, true)) {
            $claimed = is_string($claims['environment'] ?? null) ? $claims['environment'] : null;

            if ($claimed !== $this->environment) {
                throw new WrongEnvironment($claimed, $this->environment);
            }
        }

        return $claims;
    }

    /** @return array<string, mixed>|null the claims of strict base64 of a JSON object */
    protected static function decode(string $payload): ?array
    {
        $json = base64_decode($payload, true);

        if ($json === false || base64_encode($json) !== $payload) {
            return null;
        }

        try {
            return json_decode($json, false, flags: JSON_THROW_ON_ERROR) instanceof stdClass
                ? json_decode($json, true, flags: JSON_THROW_ON_ERROR)
                : null;
        } catch (JsonException) {
            return null;
        }
    }

    /** @param  array<string, mixed>  $claims */
    protected static function bodyBound(array $claims, string $method, string $body): bool
    {
        $claim = $claims['body_sha256'] ?? null;

        if (in_array($method, self::BODYLESS_METHODS, true)) {
            return $body === '' && ($claim === null || $claim === hash('sha256', ''));
        }

        return is_string($claim) && hash_equals(hash('sha256', $body), $claim);
    }
}
