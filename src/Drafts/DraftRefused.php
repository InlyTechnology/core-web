<?php

namespace Inly\CoreWeb\Drafts;

use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Statamic\Contracts\Auth\User;

/**
 * A draft the site will not write or publish, and why: a 409 (403 for a
 * person who may not write it) with a machine `error`, a sentence for the
 * person, and what it is about. The sentences are translations
 * (core-web::drafts), so a site words them for its own editors.
 */
class DraftRefused extends RuntimeException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(public readonly string $error, string $message, public readonly array $details = [], public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function notPermitted(string $name): self
    {
        return new self('not_permitted', __('core-web::drafts.not_permitted', ['name' => $name]), status: 403);
    }

    public static function notPermittedToPublish(string $name): self
    {
        return new self('not_permitted', __('core-web::drafts.not_permitted_to_publish', ['name' => $name]), status: 403);
    }

    public static function nothingToPublish(): self
    {
        return new self('nothing_to_publish', __('core-web::drafts.nothing_to_publish'));
    }

    public static function imageRequired(string $field): self
    {
        return new self('image_required', __('core-web::drafts.image_required', ['field' => $field]), ['fields' => [$field]]);
    }

    public static function notSaved(): self
    {
        return new self('not_saved', __('core-web::drafts.not_saved'), status: 500);
    }

    /** @param  bool  $workingCopy  a published entry's working copy, or else an unpublished entry's last save */
    public static function heldBy(?User $user, ?CarbonInterface $since, bool $workingCopy): self
    {
        $replace = [
            'who' => $user === null ? __('core-web::drafts.someone_without_account') : self::who($user),
            'when' => $since === null ? __('core-web::drafts.earlier') : $since->copy()->utc()->toIso8601String().' ('.$since->diffForHumans().')',
        ];

        return new self('held', __($workingCopy ? 'core-web::drafts.held_working_copy' : 'core-web::drafts.held_draft_entry', $replace), [
            'held_by' => DraftPerson::of($user),
            'since' => $since?->copy()->utc()->toIso8601String(),
        ]);
    }

    public static function proposalHeld(?User $user, CarbonInterface $since): self
    {
        $who = $user === null ? __('core-web::drafts.someone_without_account') : self::who($user);

        return new self('held', __('core-web::drafts.held_proposal', ['who' => $who, 'when' => $since->copy()->utc()->toIso8601String()]), [
            'held_by' => DraftPerson::of($user),
            'since' => $since->copy()->utc()->toIso8601String(),
        ]);
    }

    private static function who(User $user): string
    {
        $name = $user->name() ?: $user->email();

        return trim("{$name} <{$user->email()}>");
    }

    /** @param  list<string>  $fields */
    public static function restate(array $fields): self
    {
        return new self('restate', __('core-web::drafts.restate', ['fields' => implode(', ', $fields)]), ['fields' => $fields]);
    }

    public static function baseChanged(string $current): self
    {
        return new self('base_changed', __('core-web::drafts.base_changed'), ['content_hash' => $current]);
    }

    public static function catalogChanged(string $current): self
    {
        return new self('catalog_changed', __('core-web::drafts.catalog_changed'), ['catalog_hash' => $current]);
    }

    public static function revisionsOff(): self
    {
        return new self('revisions_off', __('core-web::drafts.revisions_off'));
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->error, 'message' => $this->getMessage(), ...$this->details], $this->status);
    }
}
