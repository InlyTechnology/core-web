<?php

namespace Inly\CoreWeb\Content;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Inly\CoreWeb\Client\CoreClient;
use Throwable;

/**
 * The Ed25519 keys Core seals envelopes with, read from the document Core
 * publishes at `{core}/.well-known/core-signing-keys`: cached for five
 * minutes and fetched again when an envelope names a key id the cached copy
 * lacks, at most once a minute whatever the key id, since the key id is read
 * before anything is verified.
 */
class CoreSigningKeys
{
    public const PATH = '/.well-known/core-signing-keys';

    public const ALGORITHM = 'Ed25519';

    public const TTL_SECONDS = 300;

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

    /** The raw public key `$kid` names, fetching the document again when the cached copy lacks it and the last fetch is a minute old. */
    public function publicKey(string $kid): ?string
    {
        $keys = $this->cache->get($this->cacheKey);

        if (is_array($keys) && isset($keys[$kid])) {
            return base64_decode($keys[$kid], true) ?: null;
        }

        if (! $this->cache->add($this->cacheKey.':fetched', true, self::REFETCH_SECONDS)) {
            return null;
        }

        $keys = $this->fetch();

        if ($keys === null) {
            return null;
        }

        // A fetched document replaces the cached one, so a key Core stops publishing stops opening envelopes.
        $this->cache->put($this->cacheKey, $keys, self::TTL_SECONDS);

        return isset($keys[$kid]) ? (base64_decode($keys[$kid], true) ?: null) : null;
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

    public function forget(): void
    {
        $this->cache->forget($this->cacheKey);
        $this->cache->forget($this->cacheKey.':fetched');
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
