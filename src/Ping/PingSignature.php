<?php

namespace Inly\CoreWeb\Ping;

use Illuminate\Support\Carbon;

/**
 * The signed envelope Core sends to the ping endpoint:
 * `{ payload: base64(json), signature: hex hmac_sha256(payload, secret) }`
 * where json = `{ type, id, public_id?, action, expires_at, nonce }`. The
 * secret is core-web.ping.secret; an empty one rejects everything.
 */
class PingSignature
{
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
        abort_unless(Carbon::createFromTimestamp((int) ($data['expires_at'] ?? 0))->isFuture(), 403);

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
