<?php

namespace Inly\CoreWeb\Proposals\Targets;

use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Structures\Tree;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;

/** A structured collection's page tree on one site: where each page sits. */
class CollectionTreeTarget extends TreeTarget
{
    public function __construct(public readonly string $handle, public readonly string $site) {}

    public static function make(string $handle, string $site): self
    {
        if (Collection::find($handle)?->structure()?->in($site) === null) {
            throw new InvalidArgumentException("No tree of the collection [{$handle}] on the site [{$site}].");
        }

        return new self($handle, $site);
    }

    protected function tree(): Tree
    {
        return Collection::find($this->handle)->structure()->in($this->site);
    }

    protected function allowsUrls(): bool
    {
        return false;
    }

    protected function collections(): array
    {
        return [$this->handle];
    }

    public function validate(array $value): array
    {
        $tree = parent::validate($value);
        $missing = array_diff($this->entryIds($this->current()), $this->entryIds($tree));

        // A page left out of the tree would vanish from its URL, so a proposal moves pages, never drops them.
        if ($missing !== []) {
            throw ValidationException::withMessages(['value' => 'The tree leaves out entries it holds now: '.implode(', ', $missing).'. A proposal moves pages; delete them in the CP.']);
        }

        return $tree;
    }

    /**
     * @param  array<mixed>  $branches
     * @return list<string>
     */
    private function entryIds(array $branches): array
    {
        $ids = [];

        foreach ($branches as $branch) {
            if (filled($branch['entry'] ?? null)) {
                $ids[] = (string) $branch['entry'];
            }

            array_push($ids, ...$this->entryIds((array) ($branch['children'] ?? [])));
        }

        return $ids;
    }

    public function key(): string
    {
        return "tree:{$this->handle}:{$this->site}";
    }

    public function label(): string
    {
        return Collection::find($this->handle)->title().(Site::hasMultiple() ? ' ('.Site::get($this->site)?->name().')' : '');
    }

    public function mayEdit(User $user): bool
    {
        return Gate::forUser($user)->allows('reorder', Collection::find($this->handle))
            && Gate::forUser($user)->allows('view', Site::get($this->site));
    }

    public function apply(array $value, User $user): void
    {
        $this->tree()->tree($value)->save();
    }

    public function cpUrl(): ?string
    {
        return Collection::find($this->handle)->showUrl();
    }
}
