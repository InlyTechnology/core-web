<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Illuminate\Support\Collection;
use Inly\CoreWeb\ContentSync\SyncTokens;
use Statamic\Auth\File\RoleRepository;
use Statamic\Auth\PermissionCache;
use Statamic\Contracts\Auth\RoleRepository as RoleRepositoryContract;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Role;
use Statamic\Facades\User;

trait SyncsContent
{
    /** @param  array<string, list<string>>  $roles  role handle => permissions */
    protected function roles(array $roles): void
    {
        $roles = collect($roles)->map(fn (array $permissions, string $handle) => Role::make()->handle($handle)->addPermission($permissions));

        $fake = new class($roles) extends RoleRepository
        {
            public function __construct(private Collection $fakeRoles) {}

            public function all(): Collection
            {
                return $this->fakeRoles;
            }
        };

        app()->instance(RoleRepositoryContract::class, $fake);
        Role::swap($fake);
        app(PermissionCache::class)->clear();
    }

    /** @param  list<string>  $roles */
    protected function cmsUser(string $email, bool $super = false, array $roles = []): UserContract
    {
        $user = User::make()->email($email)->data(['name' => ucfirst(strtok($email, '@'))]);

        if ($super) {
            $user->makeSuper();
        }

        $user->explicitRoles($roles);
        $user->save();

        return $user;
    }

    protected function tokenFor(UserContract $user, string $machine = 'ann@laptop'): string
    {
        return app(SyncTokens::class)->issue($user, $machine)[0];
    }
}
