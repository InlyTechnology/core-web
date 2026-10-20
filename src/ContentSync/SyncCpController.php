<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * The person's side of content sync in the CP: approving a machine's login
 * (behind the CP's own sign-in, whichever way the site has), and the tokens
 * page, where a user sees and revokes their machines and a super user every one.
 */
class SyncCpController
{
    public function authorize(Request $request, DeviceAuthorizations $devices): Response
    {
        self::assertMayPull();

        $code = (string) $request->query('code', '');
        $pending = $code === '' ? null : $devices->pending($code);

        return self::unframed(response()->view('core-web::sync.authorize', [
            'title' => __('core-web::sync.authorize_title'),
            'code' => $pending === null ? '' : $pending['user_code'],
            'machine' => $pending['machine'] ?? null,
            'ip' => $pending['ip'] ?? null,
            'started' => isset($pending['started_at']) ? Carbon::createFromTimestamp($pending['started_at'])->diffForHumans() : null,
            'unknown' => $code !== '' && $pending === null,
            'site' => rtrim((string) config('app.url'), '/'),
        ]));
    }

    public function approve(Request $request, DeviceAuthorizations $devices): Response
    {
        self::assertMayPull();

        if (! $devices->approve((string) $request->input('code'), User::current())) {
            return self::unframed(redirect(cp_route('core-web.sync.authorize'))->with('error', __('core-web::sync.code_unknown')));
        }

        return self::unframed(response()->view('core-web::sync.decided', ['title' => __('core-web::sync.authorize_title'), 'approved' => true]));
    }

    public function deny(Request $request, DeviceAuthorizations $devices): Response
    {
        self::assertMayPull();

        if (! $devices->deny((string) $request->input('code'))) {
            return self::unframed(redirect(cp_route('core-web.sync.authorize'))->with('error', __('core-web::sync.code_unknown')));
        }

        return self::unframed(response()->view('core-web::sync.decided', ['title' => __('core-web::sync.authorize_title'), 'approved' => false]));
    }

    public function tokens(SyncTokens $tokens): View
    {
        $user = User::current();

        try {
            $found = $user->isSuper() ? $tokens->all() : $tokens->forUser((string) $user->id());
        } catch (SyncTokenStoreUnavailable $e) {
            report($e);

            return view('core-web::sync.tokens', ['title' => __('core-web::sync.tokens_title'), 'tokens' => [], 'all' => $user->isSuper(), 'error' => $e->getMessage()]);
        }

        $rows = collect($found)
            ->map(function (SyncToken $token): array {
                $owner = User::find($token->userId);

                return [
                    'id' => $token->id,
                    'machine' => $token->machine,
                    'user' => $owner?->name() ?: $owner?->email() ?: __('core-web::sync.deleted_user'),
                    'last_used' => $token->lastUsedAt?->diffForHumans() ?? __('core-web::sync.never'),
                    'expires' => $token->expiresAt->toFormattedDateString(),
                ];
            })
            ->all();

        return view('core-web::sync.tokens', ['title' => __('core-web::sync.tokens_title'), 'tokens' => $rows, 'all' => $user->isSuper(), 'error' => null]);
    }

    public function revoke(string $id, SyncTokens $tokens): RedirectResponse
    {
        $user = User::current();
        $token = $tokens->find($id);

        abort_if($token === null || (! $user->isSuper() && $token->userId !== (string) $user->id()), 404);

        $tokens->revoke($token);

        return redirect(cp_route('core-web.sync.tokens'))->with('success', __('core-web::sync.revoked'));
    }

    private static function assertMayPull(): void
    {
        abort_unless(AuthenticateSyncToken::mayPull(User::current()), 403, __('core-web::sync.permission_needed'));
    }

    /** A page that hands out a token must not load in another site's frame, where a click could be steered onto Approve. */
    private static function unframed(Response $response): Response
    {
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");

        return $response;
    }
}
