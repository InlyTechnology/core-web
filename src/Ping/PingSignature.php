<?php

namespace Inly\CoreWeb\Ping;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The signed envelope Core sends to the ping endpoint:
 * `{ payload: base64(json), signature: hex hmac_sha256(payload, secret) }`
 * where json = `{ type, id, public_id?, action, expires_at, nonce }`. The
 * secret is core-web.ping.secret; an empty one rejects everything. Each
 * nonce opens once, within an expiry at most an hour (and a minute) out.
 */
class PingSignature
{
    /** The furthest out an expiry may be, so a nonce is kept in the ledger for no longer. */
    public const MAX_TTL_SECONDS = 3600;

    /** Slack for a sender whose clock runs ahead, as Core's sealed envelopes allow. */
    public const SKEW_SECONDS = 60;

    public function __construct(private ?string $secret = null) {}

    /**
     * The envelope's data, or a 403 when the signature, the payload or its
     * expiry does not hold.
     *
     * @return array<string, mixed>
     */
    public function verify(string $payload, string $signature): array
    {
        $secret = $this->secret();
        abort_if($secret === '', 403);
        abort_unless(hash_equals(hash_hmac('sha256', $payload, $secret), $signature), 403);

        $data = json_decode(base64_decode($payload, true) ?: '', true);
        abort_unless(is_array($data), 403);
        $expiresAt = (int) ($data['expires_at'] ?? 0);
        $now = Carbon::now()->getTimestamp();
        abort_unless($expiresAt > $now && $expiresAt <= $now + self::MAX_TTL_SECONDS + self::SKEW_SECONDS, 403);
        abort_unless(is_string($data['nonce'] ?? null) && preg_match('/^[0-9a-f]{32}$/D', $data['nonce']) === 1, 403);

        // Spent last, so a refused ping spends none; a replay of a captured ping finds it taken.
        abort_unless(Cache::store(config('core-web.content.store'))->add('core-web:ping-nonce:'.$data['nonce'], true, $expiresAt - $now), 403);

        return $data;
    }

    /**
     * Signs data as Core does (for tests and tooling): five minutes to live
     * and a random nonce unless the data sets them.
     *
     * @param  array<string, mixed>  $data
     * @return array{payload: string, signature: string}
     */
    public function sign(array $data): array
    {
        $payload = base64_encode(json_encode([
            ...$data,
            'expires_at' => $data['expires_at'] ?? now()->addMinutes(5)->timestamp,
            'nonce' => $data['nonce'] ?? bin2hex(random_bytes(16)),
        ], JSON_THROW_ON_ERROR));

        return ['payload' => $payload, 'signature' => $this->signature($payload)];
    }

    public function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret());
    }

    private function secret(): string
    {
        return $this->secret ?? (string) config('core-web.ping.secret');
    }
}
