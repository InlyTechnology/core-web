<?php

namespace Inly\CoreWeb\Forms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inly\CoreWeb\ContentSync\AuthenticateSyncToken;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\ContentSync\RemoteSiteError;
use Symfony\Component\HttpFoundation\Response;

/**
 * The local checkout's half of the form relay: the embed takes this as its
 * Core, and each call goes on to the live site's relay with this machine's
 * login. Core's answers come back as they are, so the embed behaves as it
 * would on the live site.
 */
class LocalFormRelayController
{
    /** The embed script changes with a Core release, so an hour-old copy is fresh enough for local work. */
    private const SCRIPT_TTL = 3600;

    public function script(string $file): Response
    {
        if (! FormRelay::available()) {
            return self::notSignedIn();
        }

        $cached = Cache::get($key = 'core-web:forms-relay:'.sha1(RemoteSite::configuredUrl()).':'.$file);

        if (! is_array($cached)) {
            $response = $this->call(fn (PendingRequest $site, string $base) => $site->get($base.'/embed/'.$file));

            if (! $response instanceof ClientResponse) {
                return $response;
            }

            if (! $response->successful()) {
                return FormRelay::answer($response);
            }

            $cached = ['body' => $response->body(), 'type' => $response->header('Content-Type') ?: 'application/javascript'];
            Cache::put($key, $cached, self::SCRIPT_TTL);
        }

        return response($cached['body'], 200, ['Content-Type' => $cached['type']]);
    }

    public function definition(Request $request, string $uuid): Response
    {
        return $this->relay(fn (PendingRequest $site, string $base) => $site->get($base.'/'.rawurlencode($uuid).'/definition', array_filter([
            'sk' => $request->query('sk'),
            'locale' => $request->query('locale'),
        ], 'is_string')));
    }

    public function nonce(string $uuid): Response
    {
        return $this->relay(fn (PendingRequest $site, string $base) => $site->get($base.'/'.rawurlencode($uuid).'/nonce'));
    }

    public function submit(Request $request, string $uuid): Response
    {
        if ($refused = FormRelay::refusesTurnstile($request) ?? FormRelay::refusesUploads($request)) {
            return $refused;
        }

        // Longer than the live site waits on its Core, so its answer arrives rather than this side's timeout.
        return $this->relay(fn (PendingRequest $site, string $base) => FormRelay::withBody($site->withHeaders(FormRelay::forwardedHeaders($request)), $request)
            ->timeout((int) config('core-web.forms.timeouts.submit', 20) + 10)
            ->post($base.'/'.rawurlencode($uuid).'/submit'));
    }

    /** @param  callable(PendingRequest, string): ClientResponse  $call */
    private function relay(callable $call): Response
    {
        if (! FormRelay::available()) {
            return self::notSignedIn();
        }

        $response = $this->call($call);

        return $response instanceof ClientResponse ? FormRelay::answer($response) : $response;
    }

    /**
     * One call to the live site's relay, with the login forgotten when the site refuses it.
     *
     * @param  callable(PendingRequest, string): ClientResponse  $call
     */
    private function call(callable $call): ClientResponse|Response
    {
        $site = RemoteSite::fromStorage();

        if ($site === null) {
            return self::notSignedIn();
        }

        try {
            $response = $call(RemoteSite::anonymous($site->url())->withToken($site->token()), $site->url().RemoteSite::endpoint('forms'));
        } catch (ConnectionException|RemoteSiteError $e) {
            return FormRelay::error('relay_unavailable', "Could not reach {$site->url()}: {$e->getMessage()}", 502);
        }

        if ($response->status() === 401 && $response->json('error') === AuthenticateSyncToken::REFUSED) {
            RemoteSite::forget();

            return self::notSignedIn();
        }

        $code = $response->json('error.code');

        // Core's own refusals (a wrong site key, a form not taking that auth) carry a code; a proxy's 401 does not.
        if ($response->redirect() || ($response->status() === 401 && ! is_string($code))) {
            return FormRelay::error('relay_unavailable', "{$site->url()} answered HTTP {$response->status()} in front of its form relay.", 502);
        }

        if ($response->status() === 404 && ! is_string($code)) {
            return FormRelay::error('relay_missing', FormRelay::NO_RELAY, 502);
        }

        return $response;
    }

    private static function notSignedIn(): Response
    {
        return FormRelay::error('relay_login_needed', 'This checkout sends forms through the live site, and is not signed in to it: run php artisan content:login.', 503);
    }
}
