<?php

namespace Inly\CoreWeb\Client;

use Illuminate\Contracts\Foundation\Application;

/**
 * The Core a site belongs to is the same on every server, so a site commits
 * it (core-web.core) instead of setting CORE_URL and CORE_CLIENT_ID per
 * environment. Env still wins where an environment differs (a staging site
 * paired with a staging Core, a local backoffice). A local checkout and the
 * test suite never get it by default: locally the live site stands in for
 * Core, and a test must not reach a real one.
 */
final class SiteCore
{
    public static function apply(Application $app): void
    {
        if ($app->environment(['local', 'testing'])) {
            return;
        }

        $config = $app['config'];

        foreach (['url' => 'base_url', 'client_id' => 'client_id'] as $committed => $client) {
            $value = (string) $config->get("core-web.core.{$committed}");

            if ($value !== '' && blank($config->get("core-web.client.{$client}"))) {
                $config->set("core-web.client.{$client}", $value);
            }
        }
    }
}
