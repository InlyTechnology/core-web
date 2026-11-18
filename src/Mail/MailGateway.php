<?php

namespace Inly\CoreWeb\Mail;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Str;

/** Registers the `core` mailer, which a site sends through only when MAIL_MAILER names it. See docs/mail-sender.md. */
final class MailGateway
{
    public static function register(Application $app): void
    {
        $config = $app['config'];

        // A site's published `mail` block replaces the package's whole key; read the gateway it leaves out.
        if (blank($config->get('core-web.mail.gateway.url')) || blank($config->get('core-web.mail.gateway.token'))) {
            $config->set('core-web.mail.gateway', array_replace(
                (array) (require __DIR__.'/../../config/core-web.php')['mail']['gateway'],
                array_filter((array) $config->get('core-web.mail.gateway'), fn (mixed $value): bool => ! blank($value)),
            ));
        }

        // A site's config/mail.php lists its own mailers; `core` is added beside them unless it declares one itself.
        if (blank($config->get('mail.mailers.core'))) {
            $config->set('mail.mailers.core', ['transport' => GatewayMailTransport::TRANSPORT]);
        }

        $extend = fn (MailManager $manager) => $manager->extend(GatewayMailTransport::TRANSPORT, fn () => new GatewayMailTransport(
            url: self::url(),
            token: (string) config('core-web.mail.gateway.token'),
            environment: Str::lower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
        ));

        $app->resolved('mail.manager') ? $extend($app->make('mail.manager')) : $app->afterResolving('mail.manager', $extend);
    }

    public static function url(): string
    {
        return rtrim((string) config('core-web.mail.gateway.url'), '/').'/mail/emails';
    }
}
