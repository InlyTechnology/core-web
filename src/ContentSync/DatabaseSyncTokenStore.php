<?php

namespace Inly\CoreWeb\ContentSync;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use RuntimeException;

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

    public function put(SyncToken $token): void
    {
        $this->run(fn () => $this->connection->table($this->table)->upsert([[
            'hash' => $token->hash,
            'id' => $token->id,
            'user_id' => $token->userId,
            'machine' => $token->machine,
            'created_at' => self::stamp($token->createdAt),
            'last_used_at' => $token->lastUsedAt === null ? null : self::stamp($token->lastUsedAt),
            'expires_at' => self::stamp($token->expiresAt),
        ]], ['hash'], ['last_used_at', 'expires_at']));
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
     * A missing table names its migration rather than surfacing as a bare query error.
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
            if ($this->connection->getSchemaBuilder()->hasTable($this->table)) {
                throw $e;
            }

            throw new RuntimeException("Content sync keeps its tokens in the `{$this->table}` table (core-web.content_sync.tokens.driver = database), which does not exist: publish core-web's migrations (php artisan vendor:publish --tag=core-web-migrations) and run php artisan migrate.", previous: $e);
        }
    }
}
