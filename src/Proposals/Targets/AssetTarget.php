<?php

namespace Inly\CoreWeb\Proposals\Targets;

use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\Asset;
use Statamic\Fields\Blueprint;

/** An asset's meta (its alt text, focal point and the container blueprint's other fields), never its file. */
class AssetTarget extends DataTarget
{
    public function __construct(public readonly string $id) {}

    public static function make(string $id): self
    {
        if (Asset::find($id) === null) {
            throw new InvalidArgumentException("No asset [{$id}].");
        }

        return new self($id);
    }

    private function asset(): AssetContract
    {
        return Asset::find($this->id);
    }

    protected function blueprint(): ?Blueprint
    {
        return $this->asset()->blueprint();
    }

    protected function stored(): array
    {
        return $this->asset()->data()->all();
    }

    public function key(): string
    {
        return "asset:{$this->id}";
    }

    public function label(): string
    {
        return $this->asset()->path();
    }

    public function mayEdit(User $user): bool
    {
        return Gate::forUser($user)->allows('edit', $this->asset());
    }

    public function apply(array $value, User $user): void
    {
        $this->asset()->data($value)->save();
    }

    public function cpUrl(): ?string
    {
        return $this->asset()->editUrl();
    }
}
