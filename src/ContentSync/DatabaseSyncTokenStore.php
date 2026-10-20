<?php

namespace Inly\CoreWeb\ContentSync;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * One row per token, for a site behind more than one server or on a disk that does not outlive a deploy.
 *
 * @internal
 */
final class DatabaseSyncTokenStore implements SyncTokenStore
{
    public function __construct(private ConnectionInterface $connection, private string $table) {}

    public function get(string $hash): ?SyncToken
    {
        $row = $this->run(fn () => $this->connection->table($this->table)->where('hash', $hash)->first());

        return $row === null ? null : $this->token((array) $row);
    }

    public function findById(string $id): ?SyncToken
    {
        $row = $this->run(fn () => $this->connection->table($this->table)->where('id', $id)->first());

        return $row === null ? null : $this->token((array) $row);
    }

    public function put(SyncToken $token): void
    {
        $this->run(fn () => $this->connection->table($this->table)->insert([
            'hash' => $token->hash,
            'id' => $token->id,
            'user_id' => $token->userId,
            'machine' => $token->machine,
            'created_at' => self::stamp($token->createdAt),
            'last_used_at' => $token->lastUsedAt === null ? null : self::stamp($token->lastUsedAt),
            'expires_at' => self::stamp($token->expiresAt),
        ]));
    }

    public function touch(string $hash, CarbonImmutable $lastUsedAt, CarbonImmutable $expiresAt): bool
    {
        $query = fn () => $this->connection->table($this->table)->where('hash', $hash);

        $changed = $this->run(fn () => $query()->update(['last_used_at' => self::stamp($lastUsedAt), 'expires_at' => self::stamp($expiresAt)]));

        // MySQL counts changed rows, not matched ones, so zero can still mean the row is there.
        return $changed > 0 || $this->run(fn () => $query()->exists());
    }

    public function forUser(string $userId): array
    {
        return $this->run(fn () => $this->connection->table($this->table)->where('user_id', $userId)->get())
            ->map(fn (object $row) => $this->token((array) $row))
            ->values()
            ->all();
    }

    public function delete(string $hash): void
    {
        $this->run(fn () => $this->connection->table($this->table)->where('hash', $hash)->delete());
    }

    public function all(): array
    {
        return $this->run(fn () => $this->connection->table($this->table)->get())
            ->map(fn (object $row) => $this->token((array) $row))
            ->values()
            ->all();
    }

    /** UTC on the way in and out, so a server in another timezone reads the same instant. */
    private static function stamp(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d H:i:s');
    }

    /** @param  array<string, mixed>  $row */
    private function token(array $row): SyncToken
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $at = fn (mixed $value) => blank($value) ? null : CarbonImmutable::parse((string) $value, 'UTC')->setTimezone($timezone)->toIso8601String();

        return SyncToken::fromArray([
            'id' => $row['id'],
            'hash' => $row['hash'],
            'user_id' => $row['user_id'],
            'machine' => $row['machine'],
            'created_at' => $at($row['created_at']),
            'last_used_at' => $at($row['last_used_at']),
            'expires_at' => $at($row['expires_at']),
        ]);
    }

    /**
     * A missing table or an unreachable database says what to set up rather than surfacing as a bare query error.
     *
     * @template T
     *
     * @param  Closure(): T  $query
     * @return T
     */
    private function run(Closure $query): mixed
    {
        try {
            return $query();
        } catch (QueryException $e) {
            $setting = "Content sync keeps its tokens in the `{$this->table}` table (core-web.content_sync.tokens.driver = database)";

            try {
                $missing = ! $this->connection->getSchemaBuilder()->hasTable($this->table);
            } catch (Throwable $unreachable) {
                throw new SyncTokenStoreUnavailable("{$setting}, but the database cannot be reached: configure its connection ({$unreachable->getMessage()}).", previous: $e);
            }

            if (! $missing) {
                throw $e;
            }

            throw new SyncTokenStoreUnavailable("{$setting}, which does not exist: publish its migration (php artisan vendor:publish --tag=core-web-sync-tokens-migration) and run php artisan migrate.", previous: $e);
        }
    }
}
