<?php

namespace Inly\CoreWeb\Client;

use Illuminate\Support\Facades\Cache;
use Inly\CoreWeb\Content\CoreEnvelope;
use Inly\CoreWeb\Content\CoreSigningKeys;

/**
 * The remote Core (core-web.remote) standing in for CORE_URL on a local
 * machine without one: the Core the developer's snapshots came from, so the
 * control panel links to it and the CP signs in through it. It stands in only
 * once the machine can read as the remote client, by its secret or a stored
 * `core:pull --login`, since that client is what the remote Core knows the
 * machine by.
 */
final class RemoteCore
{
    /** The remote Core's URL while it stands in for CORE_URL; empty otherwise, and always off a local machine. */
    public static function url(): string
    {
        if (filled(config('core-web.client.base_url'))
            || ! app()->environment('local')
            || blank(config('core-web.remote.client_id'))
            || (blank(config('core-web.remote.client_secret')) && self::login() === null)) {
            return '';
        }

        return rtrim((string) config('core-web.remote.base_url'), '/');
    }

    /** The Core a browser is sent to: CORE_URL's, or the remote Core standing in for it. */
    public static function browserUrl(): string
    {
        return rtrim((string) config('core-web.client.base_url'), '/') ?: self::url();
    }

    /** The remote client, on the token cache `core:pull` uses for it: by its secret, or else this machine's login. */
    public static function client(): CoreClient
    {
        $secret = (string) config('core-web.remote.client_secret');

        return new CoreClient(
            self::url(),
            (string) config('core-web.remote.client_id'),
            $secret,
            (string) (config('core-web.remote.token_cache_key') ?: 'core_web_remote_access_token'),
            $secret === '' ? (string) self::login()?->token() : '',
        );
    }

    /** This machine's `core:pull --login` to the configured remote Core and client, if it holds one. */
    public static function login(): ?RemoteCoreLogin
    {
        return RemoteCoreLogin::stored((string) config('core-web.remote.base_url'), (string) config('core-web.remote.client_id'));
    }

    /** Opens what the remote Core seals for the remote client, on its own published keys cached apart from CORE_URL's. */
    public static function envelope(): CoreEnvelope
    {
        $store = Cache::store(config('core-web.content.store'));

        return new CoreEnvelope(
            new CoreSigningKeys(self::url(), $store, 'core_web_remote_signing_keys'),
            (string) config('core-web.remote.client_id'),
            app()->environment(),
            $store,
        );
    }
}
