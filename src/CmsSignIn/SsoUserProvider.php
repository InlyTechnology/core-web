<?php

namespace Inly\CoreWeb\CmsSignIn;

use Illuminate\Contracts\Auth\Authenticatable;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Statamic\Auth\UserProvider;
use Statamic\Contracts\Auth\User as UserContract;

/**
 * Statamic's user provider, narrowed to the accounts Core provisions through
 * single sign-on. An account without `core_user_id` never resolves, so no
 * session, remember-me cookie or impersonation can carry it; and no
 * credentials resolve at all, which refuses password sign-in, password reset
 * and account activation wherever Statamic offers them. The hand-off route
 * logs its account in directly and needs neither.
 */
class SsoUserProvider extends UserProvider
{
    public const DRIVER = 'core-web-sso';

    /** Local development without Core: APP_ENV is `local` and the site switched core-web.cms_sign_in.local_password_sign_in on. */
    public static function localPasswordSignIn(): bool
    {
        return app()->environment('local') && config('core-web.cms_sign_in.local_password_sign_in') === true;
    }

    public function retrieveById($identifier)
    {
        $user = parent::retrieveById($identifier);

        if ($user === null || self::localPasswordSignIn()) {
            return $user;
        }

        if (! $user instanceof UserContract) {
            return null;
        }

        return app(CmsUsers::class)->isProvisioned($user) || (LocalSignIn::applies() && LocalSignIn::isMirrored($user) && RemoteSite::fromStorage() !== null) ? $user : null;
    }

    public function retrieveByCredentials(array $credentials)
    {
        return self::localPasswordSignIn() ? parent::retrieveByCredentials($credentials) : null;
    }

    public function validateCredentials(Authenticatable $user, array $credentials)
    {
        return self::localPasswordSignIn() && parent::validateCredentials($user, $credentials);
    }
}
