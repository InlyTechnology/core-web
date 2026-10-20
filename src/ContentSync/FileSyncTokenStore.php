<?php

namespace Inly\CoreWeb\ContentSync;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * One JSON file per token on a filesystem disk, since many Statamic sites have no database.
 *
 * @internal
 */
final class FileSyncTokenStore implements SyncTokenStore
{
    public function __construct(private Filesystem $disk, private string $path) {}

    public function get(string $hash): ?SyncToken
    {
        return $this->read($this->file($hash));
    }

    public function findById(string $id): ?SyncToken
    {
        return collect($this->all())->first(fn (SyncToken $token) => $token->id === $id);
    }

    public function put(SyncToken $token): void
    {
        $this->disk->put($this->file($token->hash), json_encode($token->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function touch(string $hash, CarbonImmutable $lastUsedAt, CarbonImmutable $expiresAt): bool
    {
        $token = $this->get($hash);

        if ($token === null) {
            return false;
        }

        $this->put($token->used($lastUsedAt, $expiresAt));

        return true;
    }

    public function forUser(string $userId): array
    {
        return array_values(array_filter($this->all(), fn (SyncToken $token) => $token->userId === $userId));
    }

    public function delete(string $hash): void
    {
        $this->disk->delete($this->file($hash));
    }

    public function all(): array
    {
        return collect($this->disk->files($this->path))
            ->filter(fn (string $file) => str_ends_with($file, '.json'))
            ->map(fn (string $file) => $this->read($file))
            ->filter()
            ->values()
            ->all();
    }

    private function read(string $file): ?SyncToken
    {
        if (! $this->disk->exists($file)) {
            return null;
        }

        $data = json_decode((string) $this->disk->get($file), true);

        return is_array($data) ? SyncToken::fromArray($data) : null;
    }

    private function file(string $hash): string
    {
        return $this->path.'/'.$hash.'.json';
    }
}
