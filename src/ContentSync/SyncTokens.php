<?php

namespace Inly\CoreWeb\ContentSync;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User;

/**
 * The tokens machines pull (and push) this site's content with, each acting
 * as the CMS user who approved it. A token is opaque and random, and stored
 * only as its sha256, one JSON file each on a filesystem disk
 * (core-web.content_sync.tokens), since many Statamic sites have no database:
 * a private local folder unless the site names a disk. Its lifetime
 * slides: every use pushes the expiry `token_days` out again.
 */
class SyncTokens
{
    public const PREFIX = 'cws_';

    /** A use within this many seconds of the last one writes nothing, so a pull's pages do not rewrite the file each time. */
    private const TOUCH_AFTER = 60;

    public function __construct(private Filesystem $disk, private string $path, private int $days) {}

    public static function fromConfig(): self
    {
        return new self(
            self::disk(config('core-web.content_sync.tokens.disk')),
            trim((string) config('core-web.content_sync.tokens.path', 'core-web/sync-tokens'), '/'),
            max(1, (int) config('core-web.content_sync.token_days', 30)),
        );
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
        $this->save($record);

        return [$token, $record];
    }

    /** The live record of a token, its expiry pushed out by this use; null for an unknown, revoked or expired one. */
    public function use(string $token): ?SyncToken
    {
        if (preg_match('/^'.self::PREFIX.'[A-Za-z0-9]{48}$/', $token) !== 1) {
            return null;
        }

        $record = $this->read($this->file(self::hash($token)));

        if ($record === null) {
            return null;
        }

        if ($record->expired()) {
            $this->disk->delete($this->file($record->hash));

            return null;
        }

        $now = CarbonImmutable::now();

        if ($record->lastUsedAt !== null && $record->lastUsedAt->diffInSeconds($now) < self::TOUCH_AFTER) {
            return $record;
        }

        $record = $record->used($now, $now->addDays($this->days));
        $this->save($record);

        return $record;
    }

    public function find(string $id): ?SyncToken
    {
        return collect($this->all())->first(fn (SyncToken $token) => $token->id === $id);
    }

    public function revoke(SyncToken $token): void
    {
        $this->disk->delete($this->file($token->hash));
    }

    /** @return list<SyncToken> live tokens, newest first; expired ones are cleared on the way */
    public function all(): array
    {
        return collect($this->disk->files($this->path))
            ->filter(fn (string $file) => str_ends_with($file, '.json'))
            ->map(fn (string $file) => $this->read($file))
            ->filter()
            ->reject(function (SyncToken $token): bool {
                if (! $token->expired()) {
                    return false;
                }

                $this->disk->delete($this->file($token->hash));

                return true;
            })
            ->sortByDesc(fn (SyncToken $token) => $token->createdAt->getTimestamp())
            ->values()
            ->all();
    }

    /** @return list<SyncToken> */
    public function forUser(string $userId): array
    {
        return array_values(array_filter($this->all(), fn (SyncToken $token) => $token->userId === $userId));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function save(SyncToken $token): void
    {
        $this->disk->put($this->file($token->hash), json_encode($token->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
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
