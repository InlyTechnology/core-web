<?php

namespace Inly\CoreWeb\Tests;

use Illuminate\Support\Facades\File;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\User;

/**
 * The `cms_sign_in` feature on Statamic's file users, its users, roles and
 * groups kept in a directory of the test's own, so a saved account never lands
 * in the package. `editor` is the role a Core grant maps to; `author` is one
 * assigned by hand.
 */
abstract class CmsSignInTestCase extends TestCase
{
    protected array $features = ['cms_sign_in'];

    private string $cpUsersDirectory;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $this->cpUsersDirectory = $app->storagePath('framework/testing/core-web-cp-users-'.uniqid());

        $app['config']->set('statamic.stache.stores.users.directory', $this->cpUsersDirectory.'/users');
        $app['config']->set('statamic.users.repositories.file.paths.roles', $this->cpUsersDirectory.'/roles.yaml');
        $app['config']->set('statamic.users.repositories.file.paths.groups', $this->cpUsersDirectory.'/groups.yaml');
        $app['config']->set('auth.providers.users.driver', 'statamic');
        $app['config']->set('core-web.cms_sign_in.roles', ['cms_editor' => 'editor']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists($this->cpUsersDirectory.'/users');
        Stache::store('users')->clear();

        Role::make('editor')->title('Editor')->addPermission('access cp')->save();
        Role::make('author')->title('Author')->addPermission('access cp')->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cpUsersDirectory);

        parent::tearDown();
    }

    /** An account as SSO leaves it: stamped with the Core user it was made for. */
    protected function provisioned(string $email, string $coreUserId = '7', array $roles = ['editor'], bool $super = false, ?string $password = null): UserContract
    {
        $user = User::make()->email($email)->set('core_user_id', $coreUserId)->explicitRoles($roles);

        if ($super) {
            $user->makeSuper();
        }

        if ($password !== null) {
            $user->password($password);
        }

        $user->save();

        return $user;
    }

    protected function cp(string $path = ''): string
    {
        return '/'.trim((string) config('statamic.cp.route'), '/').($path === '' ? '' : '/'.ltrim($path, '/'));
    }
}
