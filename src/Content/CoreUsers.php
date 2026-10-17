<?php

namespace Inly\CoreWeb\Content;

use Inly\CoreWeb\Drafts\DraftPerson;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;

/**
 * The CP account of a person in Core: the one single sign-on stamped with
 * their Core user id. A site whose sign-on keeps accounts another way binds
 * its own.
 */
class CoreUsers
{
    public function forCoreUser(string $coreUserId): ?UserContract
    {
        if (blank($coreUserId)) {
            return null;
        }

        return User::all()->first(fn (UserContract $user): bool => (string) $user->get(DraftPerson::CORE_USER_MARKER) === $coreUserId);
    }
}
