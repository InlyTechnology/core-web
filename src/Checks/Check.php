<?php

namespace Inly\CoreWeb\Checks;

use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftRefused;

/**
 * Something wrong a site's content can hold that a machine can find: a link
 * that will rot, a reference to something deleted, a page search engines
 * read badly. A check finds; where the fix is mechanical it also fixes,
 * always as a draft or a proposal an editor publishes, never a live change.
 */
abstract class Check
{
    /** The check's key, a lowercase slug (`own-url-links`). */
    abstract public function key(): string;

    /** What it looks for, for people. */
    abstract public function description(): string;

    /** @return iterable<Finding> */
    abstract public function detect(): iterable;

    /**
     * Fixes a finding as its author: a draft of the entry or a proposal for
     * the target. Checks without a mechanical fix leave it to an editor.
     *
     * @return array<string, mixed> the draft or proposal it made
     *
     * @throws DraftRefused
     */
    public function fix(Finding $finding, DraftAuthor $author): array
    {
        throw new DraftRefused('not_fixable', __('core-web::drafts.not_fixable', ['check' => $this->key()]), status: 422);
    }
}
