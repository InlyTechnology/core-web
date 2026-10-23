<?php

namespace Inly\CoreWeb\CmsSignIn;

use Illuminate\Support\Collection;
use Inly\CoreWeb\Drafts\DraftPerson;
use Statamic\Auth\Eloquent\User as EloquentUser;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The Statamic CP accounts Core provisions through single sign-on, the only
 * accounts that may sign in (SsoUserProvider). Each is stamped with
 * `core_user_id` when SSO creates it, and from then on Core owns its super
 * flag and the roles in core-web.cms_sign_in.roles. SSO never adopts an
 * account it did not create, and offboarding and the reconcile sweep only
 * ever touch stamped accounts. Roles assigned by hand outside the map stay.
 */
class CmsUsers
{
    public const MARKER = DraftPerson::CORE_USER_MARKER;

    /**
     * Core CMS grants → Statamic role handles. Unmapped grants are dropped, and
     * so are mapped handles missing from roles.yaml, so a renamed role refuses
     * the sign-in instead of admitting a permissionless user.
     *
     * @param  list<string>  $cmsRoles
     * @return list<string>
     */
    public function mappedRoles(array $cmsRoles): array
    {
        $map = (array) config('core-web.cms_sign_in.roles', []);

        return collect($cmsRoles)
            ->map(fn (string $grant) => $map[$grant] ?? null)
            ->filter(fn ($handle) => is_string($handle) && Role::find($handle) !== null)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Null when the address belongs to an account Core may not bind (see
     * canBind), which is then left untouched. No password is set: password
     * sign-in is refused, and without one Statamic confirms an elevated
     * session with an e-mailed code instead of a password nobody knows.
     *
     * @param  array{user_id: string, name: string, email: string, is_admin: bool}  $person
     * @param  list<string>  $roles
     */
    public function provision(array $person, array $roles): ?UserContract
    {
        $email = $person['email'];
        $existing = $this->withEmail($email);

        if ($existing->contains(fn (UserContract $user): bool => ! $this->canBind($user, $person))) {
            return null;
        }

        $user = $existing->first() ?? User::make()->email($email);

        $user->set('name', $person['name'] !== '' ? $person['name'] : $email);
        $user->set(self::MARKER, $person['user_id']);

        $kept = $this->handAssignedRoles($user);

        if ($person['is_admin']) {
            $user->makeSuper();
            $this->setRoles($user, $kept);
        } else {
            self::removeSuper($user);
            $this->setRoles($user, [...$kept, ...$roles]);
        }

        self::clearPassword($user);

        $user->save();

        return $user;
    }

    /** An eloquent user's `super` is a column that holds no null. */
    public static function removeSuper(UserContract $user): void
    {
        if ($user instanceof EloquentUser) {
            $user->model()->super = false;

            return;
        }

        $user->remove('super');
    }

    /** File users clear the hash through passwordHash(); an eloquent user has none, and its set('password') hashes whatever it is given. */
    public static function clearPassword(UserContract $user): void
    {
        if ($user instanceof EloquentUser) {
            $user->model()->password = null;

            return;
        }

        $user->passwordHash(null);
    }

    /** Strips what Core granted from the account bound to this Core user, never one that merely shares the address. */
    public function revokeCoreUser(string $coreUserId): void
    {
        $user = $this->forCoreUser($coreUserId);

        if ($user !== null) {
            $this->revokeAccount($user);
        }
    }

    public function revokeAccount(UserContract $user): void
    {
        if (! $this->holdsCoreGrant($user)) {
            return;
        }

        if ($this->isProvisioned($user)) {
            self::removeSuper($user);
        }

        $this->setRoles($user, $this->handAssignedRoles($user));
        $user->save();
    }

    /**
     * Takes the super flag from a provisioned account whose Core user is no
     * longer an admin; the mapped roles follow at their next sign-in.
     */
    public function demote(UserContract $user): void
    {
        if (! $this->isProvisioned($user) || ! $user->isSuper()) {
            return;
        }

        self::removeSuper($user);
        $user->save();
    }

    /**
     * Deletes the accounts bound to this Core user, never by address alone:
     * Core lets people change their own email unverified, so a notice naming
     * someone else's address must not take their account. Returns how many.
     */
    public function delete(string $coreUserId): int
    {
        if (blank($coreUserId)) {
            return 0;
        }

        return $this->provisioned()
            ->filter(fn (UserContract $user): bool => (string) $user->get(self::MARKER) === $coreUserId)
            ->each(fn (UserContract $user) => $user->delete())
            ->count();
    }

    /**
     * Core lets people change their own email unverified, so an address alone
     * does not prove whose account this is: only the account SSO created for
     * this Core user is theirs. One without the stamp, or stamped for someone
     * else, is refused — never adopted, whatever Core says about the person.
     *
     * @param  array{user_id: string}  $person
     */
    public function canBind(UserContract $user, array $person): bool
    {
        return $person['user_id'] !== '' && (string) $user->get(self::MARKER) === $person['user_id'];
    }

    /**
     * The address names the account's file (`users/{email}.yaml`), so one that
     * could leave that directory or nest inside it is refused outright.
     */
    public static function isUsableEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('~[/\\\\"\x00-\x1F\x7F]~', $email) !== 1;
    }

    public function isProvisioned(UserContract $user): bool
    {
        return filled($user->get(self::MARKER));
    }

    /** Whether revoking would change anything, so a stripped account is not reported again. */
    public function holdsCoreGrant(UserContract $user): bool
    {
        return ($this->isProvisioned($user) && $user->isSuper())
            || $user->explicitRoles()->keys()->intersect($this->coreRoles())->isNotEmpty();
    }

    /**
     * The account SSO made for this Core user (its `core_user_id` stamp), or
     * null: an unstamped account is never theirs, whatever its address.
     */
    public function forCoreUser(string $coreUserId): ?UserContract
    {
        if (blank($coreUserId)) {
            return null;
        }

        return $this->provisioned()->first(fn (UserContract $user): bool => (string) $user->get(self::MARKER) === $coreUserId);
    }

    /** @return Collection<int, UserContract> */
    public function provisioned(): Collection
    {
        return User::all()->filter(fn (UserContract $user): bool => $this->isProvisioned($user))->values();
    }

    /**
     * Case-insensitive: Core lowercases addresses, and the file repository's
     * own lookup is an exact match.
     *
     * @return Collection<int, UserContract>
     */
    public function withEmail(string $email): Collection
    {
        $email = mb_strtolower(trim($email));

        if ($email === '') {
            return collect();
        }

        return User::all()
            ->filter(fn (UserContract $user): bool => mb_strtolower(trim((string) $user->email())) === $email)
            ->values();
    }

    /** @param  list<string>  $roles */
    private function setRoles(UserContract $user, array $roles): void
    {
        // An eloquent user keeps its roles in a pivot table, so a removed `roles` key would be written as a column.
        if ($roles === [] && ! $user instanceof EloquentUser) {
            $user->remove('roles');

            return;
        }

        $user->explicitRoles(array_values(array_unique($roles)));
    }

    /** @return list<string> */
    private function handAssignedRoles(UserContract $user): array
    {
        return $user->explicitRoles()->keys()->diff($this->coreRoles())->values()->all();
    }

    /** @return list<string> */
    private function coreRoles(): array
    {
        return array_values(array_unique(array_filter((array) config('core-web.cms_sign_in.roles', []), 'is_string')));
    }
}
