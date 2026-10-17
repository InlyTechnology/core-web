<?php

namespace Inly\CoreWeb\Proposals\Targets;

use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Structures\Tree;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;

/** A navigation's tree on one site. */
class NavTarget extends TreeTarget
{
    public function __construct(public readonly string $handle, public readonly string $site) {}

    public static function make(string $handle, string $site): self
    {
        if (Nav::find($handle)?->in($site) === null) {
            throw new InvalidArgumentException("No navigation [{$handle}] on the site [{$site}].");
        }

        return new self($handle, $site);
    }

    protected function tree(): Tree
    {
        return Nav::find($this->handle)->in($this->site);
    }

    protected function allowsUrls(): bool
    {
        return true;
    }

    protected function collections(): array
    {
        return Nav::find($this->handle)->collections()->map->handle()->values()->all();
    }

    public function key(): string
    {
        return "nav:{$this->handle}:{$this->site}";
    }

    public function label(): string
    {
        return Nav::find($this->handle)->title().(Site::hasMultiple() ? ' ('.Site::get($this->site)?->name().')' : '');
    }

    public function mayEdit(User $user): bool
    {
        return Gate::forUser($user)->allows('edit', $this->tree());
    }

    public function apply(array $value, User $user): void
    {
        $this->tree()->tree($value)->save();
    }

    public function cpUrl(): ?string
    {
        return Nav::find($this->handle)->showUrl();
    }
}
