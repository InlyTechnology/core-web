<?php

namespace Inly\CoreWeb\Content;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Inly\CoreWeb\Client\CoreClient;
use Throwable;

/**
 * The Ed25519 keys Core seals envelopes with, read from the document Core
 * publishes at `{core}/.well-known/core-signing-keys`. A copy is due for a
 * refresh at four minutes and never opens anything past five, so a key Core
 * stops publishing stops opening envelopes within five minutes whether or not
 * Core can be read.
 */
class CoreSigningKeys
{
    public const PATH = '/.well-known/core-signing-keys';

    public const ALGORITHM = 'Ed25519';

    /** The age at which one call refetches while every other call keeps opening with the copy. */
    public const REFRESH_SECONDS = 240;

    /** The age past which the copy opens nothing, whatever a fetch does. */
    public const EXPIRES_SECONDS = 300;

    /** How long a refresh, or a fetch with no copy to fall back on, waits before the next try. */
    public const RETRY_SECONDS = 10;

    /** How often a key id the copy lacks may refetch, since the key id is read before anything is verified. */
    public const REFETCH_SECONDS = 60;

    public function __construct(
        protected string $baseUrl,
        protected Repository $cache,
        protected string $cacheKey = 'core_web_signing_keys',
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public static function fromConfig(): static
    {
        return new static(
            (string) config('core-web.client.base_url'),
            Cache::store(config('core-web.content.store')),
            (string) (config('core-web.content.key_cache_key') ?: 'core_web_signing_keys'),
        );
    }

    /** The id Core files a public key under: the first 16 hex characters of its SHA-256. */
    public static function keyId(string $publicKey): string
    {
        return substr(hash('sha256', $publicKey), 0, 16);
    }

    /** Whether `$signature`, lowercase hex, is the Ed25519 signature of `$message` by the published key `$kid`. */
    public function verifies(string $kid, string $message, string $signature): bool
    {
        if ($kid === '' || preg_match('/^[0-9a-f]{128}$/D', $signature) !== 1) {
            return false;
        }

        $publicKey = $this->publicKey($kid);

        return $publicKey !== null && sodium_crypto_sign_verify_detached((string) hex2bin($signature), $message, $publicKey);
    }

    /**
     * The raw public key `$kid` names. A copy at least four minutes old is
     * refetched by the one call that takes the retry lock, and the rest answer
     * from it until it is five minutes old; a fresh copy that lacks `$kid` is
     * refetched at most once a minute.
     */
    public function publicKey(string $kid): ?string
    {
        $cached = $this->cache->get($this->cacheKey);
        $age = now()->getTimestamp() - (is_int($cached['fetched_at'] ?? null) ? $cached['fetched_at'] : 0);
        $keys = is_array($cached) && is_array($cached['keys'] ?? null) && $age < self::EXPIRES_SECONDS ? $cached['keys'] : null;
        $due = $keys === null || $age >= self::REFRESH_SECONDS;

        if (! $due && isset($keys[$kid])) {
            return self::decodeKey($keys[$kid]);
        }

        $lock = $due ? [$this->cacheKey.':retry', self::RETRY_SECONDS] : [$this->cacheKey.':fetched', self::REFETCH_SECONDS];

        if ($this->cache->add($lock[0], true, $lock[1]) && ($fetched = $this->fetch()) !== null) {
            // A fetched document replaces the cached one, so a key Core stops publishing stops opening envelopes.
            $this->remember($fetched);
            // A key id the document just read lacks waits its minute like any other.
            $this->cache->add($this->cacheKey.':fetched', true, self::REFETCH_SECONDS);
            $keys = $fetched;
        }

        return isset($keys[$kid]) ? self::decodeKey($keys[$kid]) : null;
    }

    /**
     * Keeps `$keys` as the copy fetched now, for five minutes at most.
     *
     * @param  array<string, string>  $keys  key id => base64 public key, as `parse()` gives them
     */
    public function remember(array $keys): void
    {
        $this->cache->put($this->cacheKey, ['keys' => $keys, 'fetched_at' => now()->getTimestamp()], self::EXPIRES_SECONDS);
    }

    /**
     * The keys of a published document by the id each is recomputed to, so a
     * document cannot file one key under another's id; entries of another
     * algorithm or malformed are skipped.
     *
     * @param  array<array-key, mixed>  $document
     * @return array<string, string> key id => base64 public key
     */
    public static function parse(array $document): array
    {
        $keys = [];

        foreach ((array) ($document['keys'] ?? []) as $entry) {
            $publicKey = is_array($entry) && ($entry['alg'] ?? null) === self::ALGORITHM && is_string($entry['public_key'] ?? null)
                ? base64_decode($entry['public_key'], true)
                : false;

            if (is_string($publicKey) && strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[self::keyId($publicKey)] = base64_encode($publicKey);
            }
        }

        return $keys;
    }

    private static function decodeKey(string $key): ?string
    {
        return base64_decode($key, true) ?: null;
    }

    public function forget(): void
    {
        $this->cache->forget($this->cacheKey);
        $this->cache->forget($this->cacheKey.':fetched');
        $this->cache->forget($this->cacheKey.':retry');
    }

    /** @return array<string, string>|null null when Core could not be read, which leaves the cached copy alone */
    protected function fetch(): ?array
    {
        if ($this->baseUrl === '') {
            return null;
        }

        if (! $this->secureBaseUrl()) {
            logger()->warning('core-web: Core\'s signing keys are read over https only; core-web.client.base_url is not.', ['base_url' => $this->baseUrl]);

            return null;
        }

        try {
            $response = CoreClient::request()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                // Only from the Core the site was paired with: a redirect would read keys from wherever it points.
                ->withoutRedirecting()
                ->get($this->baseUrl.self::PATH);
        } catch (Throwable $e) {
            logger()->warning('core-web: Core\'s signing keys could not be read.', ['error' => $e->getMessage()]);

            return null;
        }

        $document = $response->successful() ? $response->json() : null;

        if (! is_array($document) || ! is_array($document['keys'] ?? null)) {
            logger()->warning('core-web: Core answered no signing key document.', ['status' => $response->status()]);

            return null;
        }

        return self::parse($document);
    }

    /** Https, except in a local or testing environment, where Core may answer plain http. */
    protected function secureBaseUrl(): bool
    {
        if (str_starts_with(strtolower($this->baseUrl), 'https://')) {
            return true;
        }

        return str_starts_with(strtolower($this->baseUrl), 'http://') && app()->environment(['local', 'testing']);
    }
}
