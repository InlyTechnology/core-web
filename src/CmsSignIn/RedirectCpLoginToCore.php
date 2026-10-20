<?php

namespace Inly\CoreWeb\CmsSignIn;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The CP is signed into from Core only: its login page redirects to Core's
 * sign-in (CORE_URL's, or locally the remote Core's), naming this site (and
 * there this address as `origin`) so Core hands the person back here and not
 * to another site it serves. A guest opening a CP page goes to Core straight
 * away, carrying that page as `next`. Statamic's other ways in (password
 * login, password reset, account activation, registration and passkey login,
 * in the CP and on the front end) are refused before they route.
 * SsoUserProvider refuses the same at the provider; this makes it visible.
 *
 * Global rather than on Statamic's CP group, which Statamic defines itself, so
 * the session is not started yet and a signed-in person asking for the login
 * page is sent through SSO too, which signs them straight back in.
 *
 * An expired CP session would open Statamic's "Resume your session" password
 * dialog, which no SSO account can answer. With Statamic's CP auth switched off
 * the CP sends the browser to its login page instead, and from there to Core
 * with the page it was on. It is switched off per request, after Statamic has
 * registered its routes, so the login route this answers stays.
 */
class RedirectCpLoginToCore
{
    public function handle(Request $request, Closure $next): Response
    {
        if (SsoUserProvider::localPasswordSignIn()) {
            return $next($request);
        }

        $cp = trim((string) config('statamic.cp.route', 'admin'), '/');

        config(['statamic.cp.auth.enabled' => false, 'statamic.cp.auth.redirect_to' => '/'.$cp.'/auth/login']);

        if ($request->is($cp.'/auth/login') && $request->isMethodSafe()) {
            return $this->toCore($this->cpPageLeft($request, $cp) ?? '/'.$cp);
        }

        abort_if($request->is(...$this->refusedPaths($cp)), 403);

        $response = $next($request);

        if ($this->sendsGuestToLogin($request, $response, $cp)) {
            return $this->toCore($request->getRequestUri());
        }

        return $response;
    }

    private function toCore(string $next): RedirectResponse
    {
        $handoff = app(CmsHandoff::class);

        // Without Core there is no way into the CP at all.
        abort_if($handoff->coreBaseUrl() === '', 503);

        // A developer machine names its own address, which the remote Core hands the sign-in back to.
        return redirect()->away($handoff->signInUrl().'?'.http_build_query([
            'site' => $handoff->siteKey(),
            ...$handoff->signsInThroughRemote() ? ['origin' => request()->getSchemeAndHttpHost()] : [],
            'next' => $next,
        ]));
    }

    /**
     * The CP page a browser was sent here from when its session expired, so the sign-in lands back on it: only a
     * page of this site's CP, never the login page itself.
     */
    private function cpPageLeft(Request $request, string $cp): ?string
    {
        $referer = (string) $request->headers->get('referer');
        $parts = parse_url($referer);

        if ($referer === '' || $parts === false || ($parts['host'] ?? null) !== $request->getHost()) {
            return null;
        }

        $path = '/'.ltrim($parts['path'] ?? '', '/');

        if (! Str::is(['/'.$cp, '/'.$cp.'/*'], $path) || Str::is('/'.$cp.'/auth/*', $path)) {
            return null;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * Statamic answers a guest on a CP page with a redirect to its login page,
     * which drops the page they asked for. A full-page GET only: an XHR or
     * Inertia visit cannot follow a redirect to another origin, so it keeps
     * the login page's route through here.
     */
    private function sendsGuestToLogin(Request $request, Response $response, string $cp): bool
    {
        return $request->isMethod('GET')
            && ! $request->ajax()
            && ! $request->header('X-Inertia')
            && $request->is($cp, $cp.'/*')
            && $response instanceof RedirectResponse
            && parse_url($response->getTargetUrl(), PHP_URL_PATH) === '/'.$cp.'/auth/login';
    }

    /** @return list<string> */
    private function refusedPaths(string $cp): array
    {
        $action = trim((string) config('statamic.routes.action', '!'), '/');

        return [
            $cp.'/auth/login',
            $cp.'/auth/password/*',
            $cp.'/auth/passkeys',
            $cp.'/auth/passkeys/*',
            $action.'/auth/login',
            $action.'/auth/password/email',
            $action.'/auth/password/reset',
            $action.'/auth/password/reset/*',
            $action.'/auth/register',
            $action.'/auth/activate',
            $action.'/auth/activate/*',
            $action.'/auth/passkeys/auth',
            $action.'/auth/passkeys/options',
            // Statamic's OAuth signs a matching address straight in, past the provider.
            self::pattern((string) config('statamic.oauth.routes.login', 'oauth/{provider}')),
            self::pattern((string) config('statamic.oauth.routes.callback', 'oauth/{provider}/callback')),
        ];
    }

    /** A route URI as a Request::is() pattern: each `{parameter}` matches any one segment. */
    private static function pattern(string $uri): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '*', trim($uri, '/'));
    }
}
