<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Statamic\Facades\User;

/**
 * The person's side of content sync in the CP: approving a machine's login
 * (behind the CP's own sign-in, whichever way the site has), and the tokens
 * page, where a user sees and revokes their machines and a super user every one.
 */
class SyncCpController
{
    public function authorize(Request $request, DeviceAuthorizations $devices): View
    {
        $code = (string) $request->query('code', '');
        $pending = $code === '' ? null : $devices->pending($code);

        return view('core-web::sync.authorize', [
            'title' => __('core-web::sync.authorize_title'),
            'code' => $pending === null ? '' : $pending['user_code'],
            'machine' => $pending['machine'] ?? null,
            'unknown' => $code !== '' && $pending === null,
            'site' => rtrim((string) config('app.url'), '/'),
        ]);
    }

    public function approve(Request $request, DeviceAuthorizations $devices): View|RedirectResponse
    {
        if (! $devices->approve((string) $request->input('code'), User::current())) {
            return redirect(cp_route('core-web.sync.authorize'))->with('error', __('core-web::sync.code_unknown'));
        }

        return view('core-web::sync.decided', ['title' => __('core-web::sync.authorize_title'), 'approved' => true]);
    }

    public function deny(Request $request, DeviceAuthorizations $devices): View|RedirectResponse
    {
        if (! $devices->deny((string) $request->input('code'))) {
            return redirect(cp_route('core-web.sync.authorize'))->with('error', __('core-web::sync.code_unknown'));
        }

        return view('core-web::sync.decided', ['title' => __('core-web::sync.authorize_title'), 'approved' => false]);
    }

    public function tokens(SyncTokens $tokens): View
    {
        $user = User::current();
        $rows = collect($user->isSuper() ? $tokens->all() : $tokens->forUser((string) $user->id()))
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

        return view('core-web::sync.tokens', ['title' => __('core-web::sync.tokens_title'), 'tokens' => $rows, 'all' => $user->isSuper()]);
    }

    public function revoke(string $id, SyncTokens $tokens): RedirectResponse
    {
        $user = User::current();
        $token = $tokens->find($id);

        abort_if($token === null || (! $user->isSuper() && $token->userId !== (string) $user->id()), 404);

        $tokens->revoke($token);

        return redirect(cp_route('core-web.sync.tokens'))->with('success', __('core-web::sync.revoked'));
    }
}
