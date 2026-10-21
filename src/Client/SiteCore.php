<?php

namespace Inly\CoreWeb\Client;

use Illuminate\Contracts\Foundation\Application;

/**
 * The Core a site belongs to is the same on every server, so a site commits
 * it (core-web.core) instead of setting CORE_URL and CORE_CLIENT_ID per
 * environment. Env still wins where an environment differs (a staging site
 * paired with a staging Core, a local backoffice), and only as a pair: a URL
 * without its client id (or the reverse) is left for the doctor to report,
 * never mixed with the committed half. A local checkout and the test suite
 * never get it by default: locally the live site stands in for Core, and a
 * test must not reach a real one.
 */
final class SiteCore
{
    public static function apply(Application $app): void
    {
        if ($app->environment(['local', 'testing'])) {
            return;
        }

        $config = $app['config'];

        if (! blank($config->get('core-web.client.base_url')) || ! blank($config->get('core-web.client.client_id'))) {
            return;
        }

        $url = (string) $config->get('core-web.core.url');

        if ($url === '') {
            return;
        }

        $config->set('core-web.client.base_url', $url);
        $config->set('core-web.client.client_id', (string) $config->get('core-web.core.client_id'));
    }
}
