<?php

namespace Inly\CoreWeb\Proposals;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Where proposals are kept: one JSON file each, on a filesystem disk
 * (core-web.drafts.proposals: `disk`, `path`), so a host whose local disk
 * does not outlive a deploy names a bucket. Decided proposals stay as the
 * record of what was applied or discarded, and by whom, in their own folder,
 * so reading the open ones (every CP page, for the nav) stays small.
 */
class ProposalStore
{
    public function __construct(private Filesystem $disk, private string $path) {}

    public static function fromConfig(): self
    {
        return new self(
            Storage::disk(config('core-web.drafts.proposals.disk') ?: null),
            trim((string) config('core-web.drafts.proposals.path', 'core-web/proposals'), '/'),
        );
    }

    public function find(string $id): ?Proposal
    {
        if (preg_match('/^[0-9a-f-]{36}$/', $id) !== 1) {
            return null;
        }

        foreach ([true, false] as $open) {
            if ($this->disk->exists($file = $this->file($id, $open))) {
                return $this->read($file);
            }
        }

        return null;
    }

    public function save(Proposal $proposal): void
    {
        $this->disk->put($this->file($proposal->id, $proposal->open()), json_encode($proposal->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        if (! $proposal->open()) {
            $this->disk->delete($this->file($proposal->id, true));
        }
    }

    /** @return list<Proposal> newest first */
    public function all(): array
    {
        return $this->in(true)->merge($this->in(false))
            ->sortByDesc(fn (Proposal $proposal) => $proposal->createdAt->getTimestamp())
            ->values()
            ->all();
    }

    /** @return list<Proposal> newest first */
    public function open(): array
    {
        return $this->in(true)
            ->sortByDesc(fn (Proposal $proposal) => $proposal->createdAt->getTimestamp())
            ->values()
            ->all();
    }

    public function openFor(string $target): ?Proposal
    {
        return collect($this->open())->first(fn (Proposal $proposal) => $proposal->target === $target);
    }

    /** @return Collection<int, Proposal> */
    private function in(bool $open): Collection
    {
        return collect($this->disk->files($this->folder($open)))
            ->filter(fn (string $file) => str_ends_with($file, '.json'))
            ->map(fn (string $file) => $this->read($file));
    }

    private function read(string $file): Proposal
    {
        return Proposal::fromArray(json_decode((string) $this->disk->get($file), true, flags: JSON_THROW_ON_ERROR));
    }

    private function folder(bool $open): string
    {
        return $this->path.'/'.($open ? 'open' : 'decided');
    }

    private function file(string $id, bool $open): string
    {
        return $this->folder($open).'/'.$id.'.json';
    }
}
