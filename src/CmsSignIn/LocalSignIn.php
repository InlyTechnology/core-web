<?php

namespace Inly\CoreWeb\CmsSignIn;

use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\CoreWebServiceProvider;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Facades\UserGroup;

/**
 * A local checkout with no Core signs its CP in through the live site
 * instead: the machine's content:login token says who the developer is on
 * the live site, and the local account mirrors that account's super flag,
 * roles and groups. The account is stamped with the live user's id, the
 * only kind SsoUserProvider lets in here besides Core's own.
 */
final class LocalSignIn
{
    public const MARKER = 'core_web_live_user_id';

    /** APP_ENV `local`, content sync pointed at a live site, and no Core to sign in through. */
    public static function applies(): bool
    {
        return app()->environment('local')
            && CoreWebServiceProvider::enabled('content_sync')
            && RemoteSite::configuredUrl() !== ''
            && app(CmsHandoff::class)->coreBaseUrl() === '';
    }

    public static function isMirrored(UserContract $user): bool
    {
        return filled($user->get(self::MARKER));
    }

    /**
     * Null when the address belongs to a local account Core provisioned or
     * one mirrored from another live user, which is left alone. Roles and groups this checkout does not
     * define are dropped, as a role map would drop them.
     *
     * @param  array{id: string, name: string, email: string, super: bool, roles: list<string>, groups: list<string>}  $person
     */
    public function provision(array $person): ?UserContract
    {
        $users = app(CmsUsers::class);
        $existing = $users->withEmail($person['email']);

        // An account Core or another live user owns is never taken over, so pointing CORE_URL at a local Core later still signs in.
        if ($existing->contains(fn (UserContract $user): bool => $users->isProvisioned($user) || (self::isMirrored($user) && (string) $user->get(self::MARKER) !== $person['id']))) {
            return null;
        }

        // By the live id first, so an address changed on the live site renames the account rather than adding a second.
        $user = User::all()->first(fn (UserContract $user): bool => (string) $user->get(self::MARKER) === $person['id'])
            ?? $existing->first()
            ?? User::make();

        $user->email($person['email']);
        $user->set('name', $person['name'] !== '' ? $person['name'] : $person['email']);
        $user->set(self::MARKER, $person['id']);

        if ($person['super']) {
            $user->makeSuper();
        } else {
            $user->remove('super');
        }

        // Set, never removed: an Eloquent user keeps roles and groups in pivot tables, not columns.
        $user->explicitRoles(array_values(array_unique(array_filter($person['roles'], fn (string $handle) => Role::find($handle) !== null))));
        $user->groups(array_values(array_unique(array_filter($person['groups'], fn (string $handle) => UserGroup::find($handle) !== null))));

        $user->save();

        return $user;
    }
}
