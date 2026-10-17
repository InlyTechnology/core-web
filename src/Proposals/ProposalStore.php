<?php

namespace Inly\CoreWeb\Proposals;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Where proposals are kept: one JSON file each, on a filesystem disk
 * (core-web.drafts.proposals: `disk`, `path`), so a host whose local disk
 * does not outlive a deploy names a bucket. Decided proposals stay as the
 * record of what was applied or discarded, and by whom.
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
        if (preg_match('/^[0-9a-f-]{36}$/', $id) !== 1 || ! $this->disk->exists($file = $this->file($id))) {
            return null;
        }

        return Proposal::fromArray(json_decode((string) $this->disk->get($file), true, flags: JSON_THROW_ON_ERROR));
    }

    public function save(Proposal $proposal): void
    {
        $this->disk->put($this->file($proposal->id), json_encode($proposal->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return list<Proposal> newest first */
    public function all(): array
    {
        return collect($this->disk->files($this->path))
            ->filter(fn (string $file) => str_ends_with($file, '.json'))
            ->map(fn (string $file) => Proposal::fromArray(json_decode((string) $this->disk->get($file), true, flags: JSON_THROW_ON_ERROR)))
            ->sortByDesc(fn (Proposal $proposal) => $proposal->createdAt->getTimestamp())
            ->values()
            ->all();
    }

    /** @return list<Proposal> */
    public function open(): array
    {
        return array_values(array_filter($this->all(), fn (Proposal $proposal) => $proposal->open()));
    }

    public function openFor(string $target): ?Proposal
    {
        return collect($this->open())->first(fn (Proposal $proposal) => $proposal->target === $target);
    }

    private function file(string $id): string
    {
        return $this->path.'/'.$id.'.json';
    }
}
