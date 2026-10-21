<?php

namespace Inly\CoreWeb\Proposals;

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftPerson;
use Inly\CoreWeb\Drafts\DraftRefused;
use Inly\CoreWeb\Support\Fingerprint;
use InvalidArgumentException;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;

/**
 * Staged changes to content Statamic keeps no revisions of, the way a draft
 * is for an entry: a source proposes the target's whole new value against
 * the fingerprint of the value it read, and an editor applies or discards it
 * in the CP. One open proposal per target: its author's next one replaces
 * it, anyone else's is refused until it is decided. A proposal whose target
 * changed since is never applied over the change.
 */
class Proposals
{
    public function __construct(private ProposalStore $store) {}

    /** The fingerprint a proposal names as its base: of the target's value now. */
    public static function base(ProposalTarget $target): string
    {
        return Fingerprint::of($target->current());
    }

    /**
     * The target's open proposal when it is the author's own, which a new one replaces.
     *
     * @throws DraftRefused when someone else holds an open proposal on it
     */
    public function assertNotHeld(DraftAuthor $author, ProposalTarget $target): ?Proposal
    {
        $open = $this->store->openFor($target->key());

        if ($open !== null && $open->userId !== (string) $author->user->id()) {
            throw DraftRefused::proposalHeld(User::find($open->userId), $open->createdAt);
        }

        return $open;
    }

    /**
     * @param  array<mixed>  $value  the target's whole new value
     * @return array<string, mixed>
     *
     * @throws DraftRefused|ValidationException
     */
    public function propose(DraftAuthor $author, ProposalTarget $target, array $value, mixed $base, bool $dryRun = false): array
    {
        if (! $target->mayEdit($author->user)) {
            throw DraftRefused::notPermitted($author->name);
        }

        if (blank($base) || ! is_string($base)) {
            throw ValidationException::withMessages(['base' => __('core-web::drafts.base_required')]);
        }

        if (! hash_equals($current = self::base($target), $base)) {
            throw DraftRefused::baseChanged($current);
        }

        $value = $target->validate($value);

        if (Fingerprint::of($value) === $current) {
            throw new DraftRefused('unchanged', __('core-web::drafts.unchanged'));
        }

        $open = $this->assertNotHeld($author, $target);

        $changed = $this->changes($target, $target->current(), $value);

        if ($dryRun) {
            return ['dry_run' => true, 'target' => $target->key(), 'changed' => $changed];
        }

        $proposal = new Proposal((string) Str::uuid(), $target->key(), $value, $current, (string) $author->user->id(), $author->provenance, $author->message, CarbonImmutable::instance(Carbon::now()));

        if ($open !== null) {
            $this->store->save($open->decide(Proposal::REPLACED, (string) $author->user->id()));
        }

        $this->store->save($proposal);

        return [...$this->describe($proposal), 'changed' => $changed];
    }

    /**
     * Applies an open proposal as the editor, when its target still holds the
     * value it was made from.
     *
     * @throws DraftRefused
     */
    public function apply(string $id, UserContract $user): Proposal
    {
        [$proposal, $target] = $this->decidable($id, $user);

        if (! hash_equals(self::base($target), $proposal->base)) {
            throw DraftRefused::baseChanged(self::base($target));
        }

        $target->apply($proposal->value, $user);
        $this->store->save($proposal->decide(Proposal::APPLIED, (string) $user->id()));

        return $proposal;
    }

    /** @throws DraftRefused */
    public function discard(string $id, UserContract $user): Proposal
    {
        [$proposal] = $this->decidable($id, $user);

        $this->store->save($proposal->decide(Proposal::DISCARDED, (string) $user->id()));

        return $proposal;
    }

    public function find(string $id): ?Proposal
    {
        return $this->store->find($id);
    }

    /** @return list<Proposal> */
    public function open(): array
    {
        return $this->store->open();
    }

    /**
     * A proposal as a source reads it back.
     *
     * @return array<string, mixed>
     */
    public function describe(Proposal $proposal): array
    {
        $target = $this->target($proposal);

        return [
            'id' => $proposal->id,
            'target' => $proposal->target,
            'label' => $target?->label(),
            'status' => $proposal->status,
            'stale' => $proposal->open() && $target !== null && ! hash_equals(self::base($target), $proposal->base),
            'by' => DraftPerson::of(User::find($proposal->userId)),
            'via' => $proposal->provenance['via'] ?? null,
            'message' => $proposal->message,
            'created_at' => $proposal->createdAt->utc()->toIso8601String(),
            'decided_by' => DraftPerson::of($proposal->decidedBy ? User::find($proposal->decidedBy) : null),
            'decided_at' => $proposal->decidedAt?->utc()->toIso8601String(),
            'cp_url' => cp_route('core-web.proposals.show', $proposal->id),
        ];
    }

    /**
     * The lines a proposal changes, keyed by where each sits.
     *
     * @param  array<mixed>  $from
     * @param  array<mixed>  $to
     * @return array<string, array{from: ?string, to: ?string}>
     */
    public function changes(ProposalTarget $target, array $from, array $to): array
    {
        $a = $target->outline($from);
        $b = $target->outline($to);

        return collect(array_keys($a + $b))
            ->filter(fn (string $key) => ($a[$key] ?? null) !== ($b[$key] ?? null))
            ->mapWithKeys(fn (string $key) => [$key => ['from' => $a[$key] ?? null, 'to' => $b[$key] ?? null]])
            ->all();
    }

    public function target(Proposal $proposal): ?ProposalTarget
    {
        try {
            return ProposalTargets::resolve($proposal->target);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array{0: Proposal, 1: ProposalTarget}
     *
     * @throws DraftRefused
     */
    private function decidable(string $id, UserContract $user): array
    {
        $proposal = $this->store->find($id);
        $target = $proposal ? $this->target($proposal) : null;

        abort_if($proposal === null || $target === null, 404);

        if (! $proposal->open()) {
            throw new DraftRefused('decided', __('core-web::drafts.decided', ['status' => $proposal->status]));
        }

        if (! $target->mayEdit($user)) {
            throw DraftRefused::notPermitted((string) ($user->name() ?: $user->email()));
        }

        return [$proposal, $target];
    }
}
