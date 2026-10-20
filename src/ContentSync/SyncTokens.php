<?php

namespace Inly\CoreWeb\ContentSync;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Statamic\Contracts\Auth\User;

/**
 * The tokens machines pull (and push) this site's content with, each acting
 * as the CMS user who approved it. A token is opaque and random, and stored
 * only as its sha256 (core-web.content_sync.tokens): one JSON file each on a
 * filesystem disk by default, since many Statamic sites have no database, or
 * a database table where several servers or deploys must see the same
 * tokens. Its lifetime slides: every use pushes the expiry `token_days` out again.
 */
class SyncTokens
{
    public const PREFIX = 'cws_';

    /** A use within this many seconds of the last one writes nothing, so a pull's pages do not rewrite the record each time. */
    private const TOUCH_AFTER = 60;

    public function __construct(private SyncTokenStore $store, private int $days) {}

    public static function fromConfig(): self
    {
        $config = (array) config('core-web.content_sync.tokens', []);
        $driver = $config['driver'] ?? 'files';

        $store = match ($driver) {
            'files' => new FileSyncTokenStore(self::disk($config['disk'] ?? null), trim((string) ($config['path'] ?? 'core-web/sync-tokens'), '/')),
            'database' => new DatabaseSyncTokenStore(DB::connection($config['connection'] ?? null), (string) ($config['table'] ?? 'core_web_sync_tokens')),
            default => throw new InvalidArgumentException("Unknown core-web.content_sync.tokens.driver [{$driver}]: use files or database."),
        };

        return new self($store, max(1, (int) config('core-web.content_sync.token_days', 30)));
    }

    /** Not the default disk: on many hosts that is a public bucket, and a token's file names its hash and user. */
    private static function disk(mixed $name): Filesystem
    {
        if (is_string($name) && $name !== '') {
            return Storage::disk($name);
        }

        return Storage::build(['driver' => 'local', 'root' => storage_path('app/private'), 'visibility' => 'private', 'throw' => false]);
    }

    /** @return array{0: string, 1: SyncToken} the token, shown once, and what is kept of it */
    public function issue(User $user, string $machine): array
    {
        $token = self::PREFIX.Str::random(48);
        $now = CarbonImmutable::now();

        $record = new SyncToken((string) Str::uuid(), self::hash($token), (string) $user->id(), $machine, $now, null, $now->addDays($this->days));
        $this->store->put($record);

        return [$token, $record];
    }

    /** The live record of a token, its expiry pushed out by this use; null for an unknown, revoked or expired one. */
    public function use(string $token): ?SyncToken
    {
        if (preg_match('/^'.self::PREFIX.'[A-Za-z0-9]{48}$/', $token) !== 1) {
            return null;
        }

        $record = $this->store->get(self::hash($token));

        if ($record === null) {
            return null;
        }

        if ($record->expired()) {
            $this->store->delete($record->hash);

            return null;
        }

        $now = CarbonImmutable::now();

        if ($record->lastUsedAt !== null && $record->lastUsedAt->diffInSeconds($now) < self::TOUCH_AFTER) {
            return $record;
        }

        $record = $record->used($now, $now->addDays($this->days));

        return $this->store->touch($record->hash, $now, $record->expiresAt) ? $record : null;
    }

    public function find(string $id): ?SyncToken
    {
        return $this->live(array_values(array_filter([$this->store->findById($id)])))[0] ?? null;
    }

    public function revoke(SyncToken $token): void
    {
        $this->store->delete($token->hash);
    }

    /** @return list<SyncToken> live tokens, newest first; expired ones are cleared on the way */
    public function all(): array
    {
        return $this->live($this->store->all());
    }

    /** @return list<SyncToken> */
    public function forUser(string $userId): array
    {
        return $this->live($this->store->forUser($userId));
    }

    /**
     * @param  list<SyncToken>  $tokens
     * @return list<SyncToken> the unexpired ones, newest first, deleting the rest
     */
    private function live(array $tokens): array
    {
        return collect($tokens)
            ->reject(function (SyncToken $token): bool {
                if (! $token->expired()) {
                    return false;
                }

                $this->store->delete($token->hash);

                return true;
            })
            ->sortByDesc(fn (SyncToken $token) => $token->createdAt->getTimestamp())
            ->values()
            ->all();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
