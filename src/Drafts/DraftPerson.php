<?php

namespace Inly\CoreWeb\Drafts;

use Statamic\Contracts\Auth\User;

/** A CP user as a draft's answers name them: with the Core user id single sign-on stamped on the account, when it has one. */
final class DraftPerson
{
    public const CORE_USER_MARKER = 'core_user_id';

    /** @return array{name: ?string, email: ?string, core_user_id: ?string}|null */
    public static function of(?User $user): ?array
    {
        return $user === null ? null : ['name' => $user->name(), 'email' => $user->email(), 'core_user_id' => $user->get(self::CORE_USER_MARKER)];
    }
}
