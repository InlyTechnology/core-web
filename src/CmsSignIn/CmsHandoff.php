<?php

namespace Inly\CoreWeb\CmsSignIn;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Client\RemoteCore;
use Inly\CoreWeb\Content\CoreEnvelope;
use Inly\CoreWeb\Content\EnvelopeRefused;
use Inly\CoreWeb\Content\WrongEnvironment;
use RuntimeException;
use Throwable;

/**
 * The CMS access envelopes Core seals with its own key for this site's app
 * client, and the calls back to Core on that client's token (README → CMS
 * sign-in). A local machine without CORE_URL signs in through the remote
 * Core instead, as that Core's developer-machine site, on its seal for the
 * remote client.
 */
class CmsHandoff
{
    public const PURPOSE = 'cms_sso';

    /** Core's notice that a person lost CMS access or was deleted. */
    public const PURPOSE_REVOKE = 'cms_revoke';

    /** Core's answer to a confirm when the person is simply not admitted, the one 403 that strips them. */
    public const NOT_ADMITTED = 'not_admitted';

    /** Core refuses a reconcile batch past this size rather than truncating it. */
    public const RECONCILE_BATCH = 500;

    /**
     * The claims of a hand-off Core sealed for this site's app client:
     * signature, audience, expiry and purpose are checked and the nonce spent.
     * It must name this CMS site, so a hand-off minted for production opens
     * nowhere else, even where two sites share a client.
     *
     * @return array<string, mixed>
     */
    public function openSealed(string $payload, string $signature, string $keyId): array
    {
        try {
            $claims = CoreEnvelope::fromConfig()->open($payload, $signature, $keyId, self::PURPOSE);
        } catch (EnvelopeRefused|WrongEnvironment) {
            abort(403);
        }

        abort_unless(($claims['site'] ?? null) === $this->siteKey(), 403);

        return $claims;
    }

    /**
     * The claims of a hand-off the remote Core sealed for the remote client.
     * Every checkout shares that client, and most answer on the same address,
     * so the hand-off must name this very address as its origin: it binds the
     * hand-off to an address, not to a machine.
     *
     * @return array<string, mixed>
     */
    public function openRemote(string $payload, string $signature, string $keyId, string $origin): array
    {
        try {
            $claims = RemoteCore::envelope()->open($payload, $signature, $keyId, self::PURPOSE);
        } catch (EnvelopeRefused|WrongEnvironment) {
            abort(403);
        }

        abort_unless(is_string($claims['origin'] ?? null) && rtrim($claims['origin'], '/') === rtrim($origin, '/'), 403);

        return $claims;
    }

    /**
     * The person and site in the body of an offboarding notice Core sealed to
     * this very request.
     *
     * @return array<string, mixed>
     */
    public function openRevoke(Request $request): array
    {
        try {
            CoreEnvelope::fromConfig()->openRequest($request, self::PURPOSE_REVOKE);
        } catch (EnvelopeRefused|WrongEnvironment) {
            abort(403);
        }

        // The seal's body_sha256 binds the body, so what it names is Core's.
        $body = json_decode((string) $request->getContent(), true);
        abort_unless(is_array($body) && ! array_is_list($body), 422);
        abort_unless(($body['site'] ?? null) === $this->siteKey(), 403);

        return $body;
    }

    /**
     * Core's current answer for a hand-off: the grant is re-read there, so one
     * revoked inside the envelope's five minutes comes back null. Throws when
     * Core cannot answer at all, which must not read as a revocation.
     *
     * @return array{user_id: string, name: string, email: string, is_admin: bool, cms_roles: list<string>, purpose: string}|null
     */
    public function confirm(string $payload, string $signature, string $keyId): ?array
    {
        $remote = $this->signsInThroughRemote();

        try {
            $response = ($remote ? RemoteCore::client() : CoreClient::fromConfig())->sendResponse('get', $this->corePath($remote ? 'verify_remote' : 'verify'), [
                'site' => $this->siteKey(),
                'payload' => $payload,
                'signature' => $signature,
                'key_id' => $keyId,
            ], timeout: 15, connectTimeout: 5);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Core could not be reached to verify the CMS hand-off', previous: $e);
        }

        // A 403 can also be the token layer refusing the call (pairing, scope), which must not strip anyone.
        if ($response->status() === 403 && ($remote || $response->json('error') === self::NOT_ADMITTED)) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException("Core could not verify the CMS hand-off [{$response->status()}]");
        }

        return [
            'user_id' => (string) $response->json('user_id'),
            'name' => trim((string) $response->json('name')),
            'email' => mb_strtolower(trim((string) $response->json('email'))),
            'is_admin' => $response->json('is_admin') === true,
            'cms_roles' => array_values(array_filter((array) $response->json('cms_roles', []), 'is_string')),
            'purpose' => (string) $response->json('purpose'),
        ];
    }

    /**
     * Core's verdict per address. Fails closed: anything short of three genuine
     * booleans for every address asked throws, because a missing `exists` would
     * otherwise read as "deleted in Core" and take the account with it.
     *
     * @param  list<string>  $emails  lowercased, at most RECONCILE_BATCH
     * @return array<string, array{exists: bool, cms_access: bool, is_admin: bool}>
     *
     * @throws ReconcileUnavailable when Core does not answer the reconcile route
     */
    public function reconcile(array $emails): array
    {
        try {
            $response = CoreClient::fromConfig()->sendResponse('post', $this->corePath('reconcile'), [
                'site' => $this->siteKey(),
                'emails' => $emails,
            ], timeout: 30, connectTimeout: 5);
        } catch (Throwable $e) {
            throw new RuntimeException('Core could not be asked to reconcile: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 404) {
            throw new ReconcileUnavailable('Core does not answer the CMS-user reconcile.');
        }

        if ($response->failed()) {
            throw new RuntimeException("Core refused the CMS-user reconcile [{$response->status()}]: ".$response->body());
        }

        return $this->verdicts($response, $emails);
    }

    /** Whether reconcile can be asked: on this site's own client, never through the remote Core. */
    public function canReconcile(): bool
    {
        return ! $this->signsInThroughRemote() && CoreClient::fromConfig()->configured();
    }

    /** The Core the CP signs in through: CORE_URL, or the remote Core on a local machine without one. */
    public function coreBaseUrl(): string
    {
        return RemoteCore::browserUrl();
    }

    public function signsInThroughRemote(): bool
    {
        return RemoteCore::url() !== '';
    }

    /** This site's key among the CMS sites Core signs into. */
    public function siteKey(): string
    {
        return $this->signsInThroughRemote()
            ? (string) config('core-web.cms_sign_in.remote_site', 'local')
            : (string) config('core-web.cms_sign_in.site');
    }

    /** Where Core sends the browser to sign in. */
    public function signInUrl(): string
    {
        return $this->coreBaseUrl().'/'.ltrim($this->corePath('sign_in'), '/');
    }

    private function corePath(string $endpoint): string
    {
        return (string) config("core-web.cms_sign_in.core.{$endpoint}");
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, array{exists: bool, cms_access: bool, is_admin: bool}>
     */
    private function verdicts(Response $response, array $emails): array
    {
        $users = (array) $response->json('users', []);
        $verdicts = [];

        foreach ($emails as $email) {
            $verdict = $users[$email] ?? null;

            if (! is_array($verdict) || ! is_bool($verdict['exists'] ?? null) || ! is_bool($verdict['cms_access'] ?? null) || ! is_bool($verdict['is_admin'] ?? null)) {
                throw new RuntimeException("Core gave no usable verdict for {$email}; aborting rather than guessing.");
            }

            $verdicts[$email] = ['exists' => $verdict['exists'], 'cms_access' => $verdict['cms_access'], 'is_admin' => $verdict['is_admin']];
        }

        return $verdicts;
    }
}
