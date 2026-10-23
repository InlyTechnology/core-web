<?php

namespace Inly\CoreWeb\CmsSignIn;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inly\CoreWeb\ContentSync\RemoteLoginNeeded;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\ContentSync\RemoteSiteError;
use Inly\CoreWeb\Forms\RestrictToLoopback;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a local CP in as the developer's live-site account (LocalSignIn),
 * asking the live site who the machine's token belongs to on every sign-in,
 * so a revoked machine or a changed grant lands at the next one. Only from
 * the machine itself, as the local form relay (RestrictToLoopback).
 */
class LocalSignInController
{
    public function __invoke(Request $request, LocalSignIn $signIn): Response
    {
        abort_unless(LocalSignIn::applies(), 404);

        // It signs in as the developer's live account, so a share, tunnel or LAN visitor gets no further.
        if (! RestrictToLoopback::allows($request)) {
            return self::explain(__('core-web::sync.local_sign_in_loopback'), 403);
        }

        $remote = RemoteSite::fromStorage();

        if ($remote === null) {
            return self::explain(__('core-web::sync.local_login_needed', ['site' => RemoteSite::configuredUrl()]), 401);
        }

        try {
            $response = $remote->get('me');
        } catch (RemoteLoginNeeded) {
            return self::explain(__('core-web::sync.local_login_needed', ['site' => $remote->url()]), 401);
        } catch (RemoteSiteError $e) {
            return self::explain(__('core-web::sync.local_site_unreachable', ['site' => $remote->url(), 'reason' => $e->getMessage()]), 503);
        }

        if ($response->status() === 404) {
            return self::explain(__('core-web::sync.local_site_outdated', ['site' => $remote->url()]), 503);
        }

        $person = self::person($response->json());

        if (! $response->successful() || $person === null) {
            return self::explain(__('core-web::sync.local_site_unreachable', ['site' => $remote->url(), 'reason' => 'HTTP '.$response->status()]), 503);
        }

        $user = $signIn->provision($person);

        if ($user === null) {
            Log::warning('CMS sign-in: refused to take over a local CP account mirrored from another live user', ['live_user_id' => $person['id']]);

            return self::explain(__('core-web::sync.local_account_taken', ['email' => $person['email']]), 403);
        }

        Auth::guard((string) config('statamic.users.guards.cp', 'web'))->login($user);
        $request->session()->regenerate();

        return redirect(HandoffController::safeNext($request->query('next')) ?? cp_route('index'));
    }

    /**
     * @return array{id: string, name: string, email: string, super: bool, roles: list<string>, groups: list<string>}|null
     */
    private static function person(mixed $body): ?array
    {
        if (! is_array($body) || ! is_string($body['id'] ?? null) || $body['id'] === '' || ! is_string($body['email'] ?? null) || ! CmsUsers::isUsableEmail($body['email'])) {
            return null;
        }

        return [
            'id' => $body['id'],
            'name' => is_string($body['name'] ?? null) ? $body['name'] : '',
            'email' => $body['email'],
            'super' => ($body['super'] ?? false) === true,
            'roles' => self::handles($body['roles'] ?? []),
            'groups' => self::handles($body['groups'] ?? []),
        ];
    }

    /** @return list<string> */
    private static function handles(mixed $list): array
    {
        return is_array($list) ? array_values(array_filter($list, fn (mixed $handle) => is_string($handle) && $handle !== '')) : [];
    }

    /** Plain text: the CP cannot render a page for someone not signed in, and the fix is a terminal command. */
    private static function explain(string $message, int $status): Response
    {
        return response($message, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
