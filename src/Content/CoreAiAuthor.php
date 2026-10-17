<?php

namespace Inly\CoreWeb\Content;

use Illuminate\Http\Request;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Drafts\DraftRefused;

/**
 * The author of a change Core AI makes for a person in Core: their CP
 * account (CoreUsers), marked `via: core-ai` with Core's request and
 * approval, and a revision message naming them (core-web::drafts.core_ai).
 */
final class CoreAiAuthor
{
    public const VIA = 'core-ai';

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'actor' => ['required', 'array'],
            'actor.core_user_id' => ['required', 'string', 'max:64'],
            'actor.name' => ['required', 'string', 'max:200'],
            'actor.email' => ['required', 'email', 'max:254'],
            'summary' => ['nullable', 'string', 'max:500'],
            'core_request_id' => ['nullable', 'string', 'max:128'],
            'core_approval_id' => ['nullable', 'string', 'max:128'],
        ];
    }

    /** @throws DraftRefused for a person without a CP account */
    public static function from(Request $request, string $what = 'draft'): DraftAuthor
    {
        $actor = (array) $request->input('actor');
        $name = trim((string) $actor['name']);
        $user = app(CoreUsers::class)->forCoreUser((string) $actor['core_user_id'])
            ?? throw new DraftRefused('no_cms_user', __('core-web::drafts.no_cms_user', ['name' => $name]), status: 403);

        $message = __("core-web::drafts.core_ai_{$what}", ['name' => $name]);
        $summary = trim((string) $request->input('summary'));

        return new DraftAuthor(
            $user,
            $name,
            DraftProvenance::make(self::VIA, [
                'core_user_id' => (string) $actor['core_user_id'],
                'core_request_id' => $request->input('core_request_id'),
                'core_approval_id' => $request->input('core_approval_id'),
            ]),
            $summary === '' ? $message : "{$message}: {$summary}",
        );
    }
}
