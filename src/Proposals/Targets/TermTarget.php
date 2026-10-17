<?php

namespace Inly\CoreWeb\Proposals\Targets;

use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Facades\Site;
use Statamic\Facades\Term;
use Statamic\Fields\Blueprint;

/** A taxonomy term's values on one site (its title among them). */
class TermTarget extends DataTarget
{
    public function __construct(public readonly string $taxonomy, public readonly string $slug, public readonly string $site) {}

    public static function make(string $taxonomy, string $slug, string $site): self
    {
        if (Term::find("{$taxonomy}::{$slug}")?->in($site) === null) {
            throw new InvalidArgumentException("No term [{$taxonomy}::{$slug}] on the site [{$site}].");
        }

        return new self($taxonomy, $slug, $site);
    }

    private function term(): TermContract
    {
        return Term::find("{$this->taxonomy}::{$this->slug}")->in($this->site);
    }

    protected function blueprint(): ?Blueprint
    {
        return $this->term()->blueprint();
    }

    protected function stored(): array
    {
        return $this->term()->data()->all();
    }

    protected function internal(): array
    {
        return ['id', 'blueprint', 'updated_by', 'updated_at'];
    }

    protected function outside(): array
    {
        return ['slug'];
    }

    public function key(): string
    {
        return "term:{$this->taxonomy}:{$this->slug}:{$this->site}";
    }

    public function label(): string
    {
        return $this->term()->title().(Site::hasMultiple() ? ' ('.Site::get($this->site)?->name().')' : '');
    }

    public function mayEdit(User $user): bool
    {
        return Gate::forUser($user)->allows('edit', $this->term());
    }

    public function apply(array $value, User $user): void
    {
        $term = $this->term();
        $internal = array_intersect_key($term->data()->all(), array_flip($this->internal()));

        $term->data([...$internal, ...$value])->set('updated_by', $user->id())->set('updated_at', now()->timestamp)->save();
    }

    public function cpUrl(): ?string
    {
        return $this->term()->editUrl();
    }
}
