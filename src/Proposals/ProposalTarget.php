<?php

namespace Inly\CoreWeb\Proposals;

use Illuminate\Validation\ValidationException;
use Statamic\Contracts\Auth\User;

/**
 * Content Statamic keeps no revisions of (a navigation tree, a collection's
 * page tree, a global's values, a term, an asset's meta): what a proposal
 * changes, whole. A proposal holds the full new value and the fingerprint of
 * the value it was made from, and an editor applies it in the CP.
 */
interface ProposalTarget
{
    /** The target's address, `{type}:{…}` (ProposalTargets::resolve() reads it back). */
    public function key(): string;

    /** A name for people: the nav, global or term and its site. */
    public function label(): string;

    /** @return array<mixed> the value as stored now */
    public function current(): array;

    /**
     * The proposed value, checked as the CP would check a save of it.
     *
     * @param  array<mixed>  $value
     * @return array<mixed> the value as it would be stored
     *
     * @throws ValidationException keyed `value.{path}`
     */
    public function validate(array $value): array;

    /** Whether the user may change the target, and so propose or apply a change to it. */
    public function mayEdit(User $user): bool;

    /** @param  array<mixed>  $value  a value validate() returned */
    public function apply(array $value, User $user): void;

    /**
     * The value as lines to compare, keyed by where each sits: a field handle,
     * or a branch's position in a tree.
     *
     * @param  array<mixed>  $value
     * @return array<string, string>
     */
    public function outline(array $value): array;

    public function cpUrl(): ?string;
}
