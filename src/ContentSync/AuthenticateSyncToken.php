<?php

namespace Inly\CoreWeb\ContentSync;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\User as Users;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `core-web.sync`: the request carries a machine's sync
 * token as its bearer, and runs as the CMS user who approved it, so every
 * policy check below it is that person's. A token that is unknown, expired
 * or revoked, or whose user may no longer pull content, is a 401.
 */
class AuthenticateSyncToken
{
    public const ALIAS = 'core-web.sync';

    /** Tells the machine its token's expiry moved, so it keeps its stored login current. */
    public const EXPIRES_HEADER = 'X-Core-Web-Token-Expires-At';

    /** The `error` of this middleware's own 401, so a machine forgets its login on that and not on a proxy's basic-auth 401. */
    public const REFUSED = 'sync_token_refused';

    /** Needed, besides CP access, to approve a machine and to use its token; a super user always may. */
    public const PERMISSION = 'pull content';

    public function __construct(private SyncTokens $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $record = $this->tokens->use((string) $request->bearerToken());
        $user = $record === null ? null : Users::find($record->userId);

        if ($record === null || $user === null || ! self::mayPull($user)) {
            return new JsonResponse([
                'error' => self::REFUSED,
                'message' => __('core-web::sync.login_needed'),
            ], 401);
        }

        $guard = (string) config('statamic.users.guards.cp', 'web');
        Auth::shouldUse($guard);
        Auth::guard($guard)->setUser(self::authenticatable($user));
        $request->attributes->set(self::class, $record);

        $response = $next($request);
        $response->headers->set(self::EXPIRES_HEADER, $record->expiresAt->toIso8601String());

        return $response;
    }

    /** The token behind this request, for the endpoints that act on it (logout). */
    public static function token(Request $request): ?SyncToken
    {
        $token = $request->attributes->get(self::class);

        return $token instanceof SyncToken ? $token : null;
    }

    public static function mayPull(User $user): bool
    {
        return $user->isSuper() || ($user->hasPermission('access cp') && $user->hasPermission(self::PERMISSION));
    }

    /** The guard holds what the user provider returns: the Eloquent model behind an Eloquent user. */
    private static function authenticatable(User $user): Authenticatable
    {
        if (method_exists($user, 'model') && $user->model() instanceof Authenticatable) {
            return $user->model();
        }

        return $user;
    }
}
