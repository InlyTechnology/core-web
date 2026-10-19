<?php

namespace Inly\CoreWeb\Drafts;

use Statamic\Contracts\Auth\User;

/**
 * Who a draft is written as and how it is marked: the CP user it is saved
 * as (whose permissions decide what it may do), the name refusals address,
 * the provenance stamped on its revision (DraftProvenance) and the revision
 * message. Each source builds its own: Core AI for the person in Core,
 * a push for the developer who pushed.
 */
final readonly class DraftAuthor
{
    /** @param  array<string, string>  $provenance */
    public function __construct(
        public User $user,
        public string $name,
        public array $provenance,
        public string $message,
    ) {}
}
