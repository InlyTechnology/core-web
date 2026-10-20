<?php

namespace Inly\CoreWeb\CmsSignIn;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Receives Core's sealed CMS hand-off and signs the person into the CP. The
 * envelope is opened here first (signature, expiry, audience, purpose, single
 * use), then confirmed with Core, whose answer, not the payload, decides the
 * grant: admins become super, everyone else gets the roles mapped from their
 * Core CMS grants. Rewritten on every sign-in, so a promotion, demotion or
 * revocation in Core lands at the next pass; a refused hand-off also strips
 * what Core granted before. Only the account SSO created for this Core user
 * is signed into (CmsUsers::canBind).
 */
class HandoffController
{
    public function __invoke(Request $request, CmsHandoff $handoff, CmsUsers $users): RedirectResponse
    {
        $request->validate([
            'payload' => ['required', 'string'],
            'signature' => ['required', 'string'],
            'key_id' => ['required', 'string'],
        ]);

        $payload = (string) $request->query('payload');
        $signature = (string) $request->query('signature');
        $keyId = (string) $request->query('key_id');

        // Opening it spends its nonce.
        $claims = $handoff->openSealed($payload, $signature, $keyId);

        try {
            $person = $handoff->confirm($payload, $signature, $keyId);
        } catch (RuntimeException $e) {
            Log::warning('CMS sign-in: Core could not verify the hand-off', ['error' => $e->getMessage()]);

            abort(503);
        }

        if ($person === null) {
            $users->revokeCoreUser((string) ($claims['user_id'] ?? ''));

            abort(403);
        }

        abort_unless($person['purpose'] === CmsHandoff::PURPOSE && CmsUsers::isUsableEmail($person['email']) && $person['user_id'] !== '', 403);
        // Core's answer must be about the person the hand-off named.
        abort_unless($person['user_id'] === (string) ($claims['user_id'] ?? ''), 403);

        $roles = $users->mappedRoles($person['cms_roles']);

        if (! $person['is_admin'] && $roles === []) {
            $users->revokeCoreUser($person['user_id']);

            abort(403);
        }

        $user = $users->provision($person, $roles);

        if ($user === null) {
            Log::warning('CMS sign-in: refused to take over a CP account Core does not own', ['core_user_id' => $person['user_id']]);

            abort(403);
        }

        Auth::guard((string) config('statamic.users.guards.cp', 'web'))->login($user);
        $request->session()->regenerate();

        return redirect($this->safeNext($request->query('next')) ?? cp_route('index'));
    }

    /** Only a same-site path may ride along, or the hand-off is an open redirect. */
    private function safeNext(mixed $next): ?string
    {
        if (! is_string($next) || ! str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return null;
        }

        if (str_contains($next, '\\') || preg_match('/[\x00-\x1F\x7F]/', $next) === 1) {
            return null;
        }

        return $next;
    }
}
