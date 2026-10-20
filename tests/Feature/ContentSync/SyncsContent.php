<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\ContentSync\SyncTokens;
use Statamic\Auth\File\RoleRepository;
use Statamic\Auth\PermissionCache;
use Statamic\Contracts\Auth\RoleRepository as RoleRepositoryContract;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Role;
use Statamic\Facades\User;

trait SyncsContent
{
    /** @var array<string, PromiseInterface> the site's device flow answers, by path, in place of this app's */
    private array $login = [];

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

    /** The site's device flow approves at once and hands out the given token; every other call reaches this app. */
    protected function approveLoginsWith(string $token): void
    {
        Sleep::fake();
        Process::fake();
        $this->fakeLoginEndpoints(Http::response(['token' => $token, 'expires_at' => now()->addDays(30)->toIso8601String(), 'user' => ['name' => 'Boss', 'email' => 'boss@example.com']]));
    }

    protected function fakeLoginEndpoints(PromiseInterface $tokenResponse): void
    {
        $this->login = [
            RemoteSite::endpoint('device') => Http::response([
                'device_code' => 'device-code',
                'user_code' => 'BCDF-GHJK',
                'verification_uri_complete' => 'https://remote.test/cp/core-web/sync/authorize?code=BCDF-GHJK',
                'expires_in' => 600,
                'interval' => 5,
            ]),
            RemoteSite::endpoint('token') => $tokenResponse,
        ];
    }

    /** The faked device flow's answer to a call on the remote site, or null for one this app answers. */
    protected function loginAnswer(Request $request): ?PromiseInterface
    {
        return $this->login[(string) parse_url($request->url(), PHP_URL_PATH)] ?? null;
    }

    /** How many sign-ins the run started. */
    protected function signIns(): int
    {
        return Http::recorded(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === RemoteSite::endpoint('device'))->count();
    }
}
