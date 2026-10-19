<?php

namespace Inly\CoreWeb\Proposals\Targets;

use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Globals\Variables;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;

/** A global set's values on one site. */
class GlobalTarget extends DataTarget
{
    public function __construct(public readonly string $handle, public readonly string $site) {}

    public static function make(string $handle, string $site): self
    {
        if (GlobalSet::find($handle)?->in($site) === null) {
            throw new InvalidArgumentException("No global [{$handle}] on the site [{$site}].");
        }

        return new self($handle, $site);
    }

    private function variables(): Variables
    {
        return GlobalSet::find($this->handle)->in($this->site);
    }

    protected function blueprint(): ?Blueprint
    {
        return $this->variables()->blueprint();
    }

    protected function stored(): array
    {
        return $this->variables()->data()->all();
    }

    public function key(): string
    {
        return "global:{$this->handle}:{$this->site}";
    }

    public function label(): string
    {
        return GlobalSet::find($this->handle)->title().(Site::hasMultiple() ? ' ('.Site::get($this->site)?->name().')' : '');
    }

    public function mayEdit(User $user): bool
    {
        return Gate::forUser($user)->allows('edit', $this->variables());
    }

    public function apply(array $value, User $user): void
    {
        $this->variables()->data($this->merged($value))->save();
    }

    public function cpUrl(): ?string
    {
        return $this->variables()->editUrl();
    }
}
