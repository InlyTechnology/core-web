<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\User as Users;

/**
 * The pending logins of the device flow (RFC 8628, as `gh auth login` does
 * it): a machine asks for a code, a person approves it in the CP, the
 * machine trades its device code for a token, once. Pending requests live in
 * the cache (core-web.content_sync.store, shared by every server behind the
 * site), keyed by a hash of the device code, never the code itself.
 */
class DeviceAuthorizations
{
    public const EXPIRES_IN = 600;

    public const INTERVAL = 5;

    /** No vowels and nothing that reads as another letter or digit (RFC 8628 §6.1), so a code is not misread or a word. */
    private const ALPHABET = 'BCDFGHJKLMNPQRSTVWXZ';

    public function __construct(private Repository $cache, private SyncTokens $tokens) {}

    public static function fromConfig(): self
    {
        return new self(Cache::store(config('core-web.content_sync.store') ?: null), SyncTokens::fromConfig());
    }

    /** @return array{device_code: string, user_code: string, expires_in: int, interval: int} */
    public function start(string $machine, string $ip): array
    {
        $deviceCode = Str::random(64);
        $userCode = self::userCode();
        $hash = hash('sha256', $deviceCode);

        $this->cache->put($this->key($hash), [
            'machine' => $machine,
            'ip' => $ip,
            'started_at' => now()->getTimestamp(),
            'user_code' => $userCode,
            'status' => 'pending',
            'user_id' => null,
            'interval' => self::INTERVAL,
            'polled_at' => null,
            'expires_at' => now()->addSeconds(self::EXPIRES_IN)->getTimestamp(),
        ], self::EXPIRES_IN + 60);
        $this->cache->put($this->codeKey($userCode), $hash, self::EXPIRES_IN);

        return ['device_code' => $deviceCode, 'user_code' => $userCode, 'expires_in' => self::EXPIRES_IN, 'interval' => self::INTERVAL];
    }

    /**
     * A pending request by the code the person was shown (ABCD-EFGH, or as typed), with the machine that asked.
     *
     * @return array<string, mixed>|null
     */
    public function pending(string $userCode): ?array
    {
        $hash = $this->cache->get($this->codeKey(self::normalise($userCode)));
        $request = is_string($hash) ? $this->cache->get($this->key($hash)) : null;

        if (! is_array($request) || $request['status'] !== 'pending' || $request['expires_at'] < now()->getTimestamp()) {
            return null;
        }

        return ['hash' => $hash, ...$request];
    }

    public function approve(string $userCode, User $user): bool
    {
        return $this->decide($userCode, ['status' => 'approved', 'user_id' => (string) $user->id()]);
    }

    public function deny(string $userCode): bool
    {
        return $this->decide($userCode, ['status' => 'denied']);
    }

    /**
     * What the machine polling with its device code gets: an error the CLI
     * keeps polling on (`authorization_pending`, `slow_down`) or stops on
     * (`access_denied`, `expired_token`), or the token, exactly once.
     *
     * @return array{error: string}|array{token: string, record: SyncToken, user: User}
     */
    public function exchange(string $deviceCode): array
    {
        $hash = hash('sha256', $deviceCode);
        $lock = $this->cache->lock($this->key($hash).':lock', 10);

        if (! $lock->get()) {
            return ['error' => 'slow_down'];
        }

        try {
            return $this->exchangeLocked($hash);
        } finally {
            $lock->release();
        }
    }

    /** @return array{error: string}|array{token: string, record: SyncToken, user: User} */
    private function exchangeLocked(string $hash): array
    {
        $request = $this->cache->get($this->key($hash));

        if (! is_array($request) || $request['expires_at'] < now()->getTimestamp()) {
            $this->forget($hash, $request);

            return ['error' => 'expired_token'];
        }

        if ($request['status'] === 'denied') {
            $this->forget($hash, $request);

            return ['error' => 'access_denied'];
        }

        $now = now()->getTimestamp();

        if ($request['polled_at'] !== null && $now - $request['polled_at'] < $request['interval']) {
            // RFC 8628 §3.5: a client polling too fast waits 5 seconds longer from then on.
            $request['interval'] += 5;
            $request['polled_at'] = $now;
            $this->cache->put($this->key($hash), $request, max(1, $request['expires_at'] - $now + 60));

            return ['error' => 'slow_down'];
        }

        if ($request['status'] !== 'approved') {
            $request['polled_at'] = $now;
            $this->cache->put($this->key($hash), $request, max(1, $request['expires_at'] - $now + 60));

            return ['error' => 'authorization_pending'];
        }

        $this->forget($hash, $request);
        $user = Users::find($request['user_id']);

        if ($user === null || ! AuthenticateSyncToken::mayPull($user)) {
            return ['error' => 'access_denied'];
        }

        [$token, $record] = $this->tokens->issue($user, $request['machine']);

        return ['token' => $token, 'record' => $record, 'user' => $user];
    }

    /** @param  array<string, mixed>  $changes */
    private function decide(string $userCode, array $changes): bool
    {
        $request = $this->pending($userCode);

        if ($request === null) {
            return false;
        }

        $hash = $request['hash'];
        unset($request['hash']);

        $this->cache->put($this->key($hash), [...$request, ...$changes], max(1, $request['expires_at'] - now()->getTimestamp() + 60));
        $this->cache->forget($this->codeKey($request['user_code']));

        return true;
    }

    private function forget(string $hash, mixed $request): void
    {
        $this->cache->forget($this->key($hash));

        if (is_array($request) && is_string($request['user_code'] ?? null)) {
            $this->cache->forget($this->codeKey($request['user_code']));
        }
    }

    public static function normalise(string $userCode): string
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($userCode)) ?? '';

        return strlen($letters) === 8 ? substr($letters, 0, 4).'-'.substr($letters, 4) : $letters;
    }

    private static function userCode(): string
    {
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    private function key(string $hash): string
    {
        return 'core-web:sync:device:'.$hash;
    }

    private function codeKey(string $userCode): string
    {
        return 'core-web:sync:code:'.$userCode;
    }
}
