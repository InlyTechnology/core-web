<?php

namespace Inly\CoreWeb\Testing;

use Inly\CoreWeb\Content\CoreEnvelope;
use Inly\CoreWeb\Content\CoreSigningKeys;
use InvalidArgumentException;

/**
 * Seals envelopes as Core does with its own Ed25519 key, for a site's tests
 * and tooling: the claims encoded with slashes and text outside ASCII
 * unescaped, the base64 of them signed. `document()` is the key document
 * Core would publish for it.
 */
final class CoreEnvelopeSealer
{
    private string $secretKey;

    private string $publicKey;

    public function __construct(string $seed)
    {
        if (strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new InvalidArgumentException('An Ed25519 seed is 32 bytes.');
        }

        $pair = sodium_crypto_sign_seed_keypair($seed);
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
    }

    /** The key FakesCore publishes as Core's: fixed, so a test run is reproducible, and never Core's. */
    public static function test(): self
    {
        return new self(hash('sha256', 'inly/core-web test signing key', true));
    }

    public function kid(): string
    {
        return CoreSigningKeys::keyId($this->publicKey);
    }

    /** @return array{keys: list<array{kid: string, alg: string, public_key: string}>} */
    public function document(): array
    {
        return ['keys' => [['kid' => $this->kid(), 'alg' => CoreSigningKeys::ALGORITHM, 'public_key' => base64_encode($this->publicKey)]]];
    }

    /**
     * The headers of exactly these claims, in their order.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, string>
     */
    public function seal(array $claims): array
    {
        $payload = base64_encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            CoreEnvelope::PAYLOAD_HEADER => $payload,
            CoreEnvelope::SIGNATURE_HEADER => bin2hex(sodium_crypto_sign_detached($payload, $this->secretKey)),
            CoreEnvelope::KEY_ID_HEADER => $this->kid(),
        ];
    }

    /**
     * The headers of a request sealed for this site as Core seals it: the
     * site's client id as `aud`, the purpose, the site's environment, and the
     * method, path, query and body of `$target` (a raw request target).
     * `$claims` replace the claims of the same name; a null one is left out.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, string>
     */
    public function requestHeaders(string $method, string $target, string $purpose, ?string $body = null, array $claims = []): array
    {
        $method = strtoupper($method);
        [$path, $queryString] = CoreEnvelope::splitTarget($target);
        parse_str($queryString, $query);

        $sealed = array_filter([
            'aud' => (string) config('core-web.client.client_id'),
            'expires_at' => now()->getTimestamp() + CoreEnvelope::TTL_SECONDS,
            'nonce' => bin2hex(random_bytes(16)),
            'purpose' => $purpose,
            'environment' => (string) (config('core-web.content.environment') ?: app()->environment()),
            'method' => $method,
            'path' => $path,
            'query' => (object) CoreEnvelope::canonicalQuery($query),
            'body_sha256' => in_array($method, ['GET', 'HEAD'], true) ? null : hash('sha256', $body ?? ''),
        ], fn ($value) => $value !== null);

        foreach ($claims as $claim => $value) {
            $sealed[$claim] = $value;
        }

        return $this->seal(array_filter($sealed, fn ($value) => $value !== null));
    }
}
