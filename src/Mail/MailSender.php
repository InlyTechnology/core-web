<?php

namespace Inly\CoreWeb\Mail;

use Illuminate\Contracts\Foundation\Application;

/** Builds mail.from.address from core-web.mail.from, so a site commits a local part instead of a whole address per environment. */
final class MailSender
{
    /** Laravel's stock config/mail.php default, which every site that never set an address carries. */
    private const LARAVEL_DEFAULT = 'hello@example.com';

    public static function apply(Application $app): void
    {
        $config = $app['config'];

        $from = array_filter((array) $config->get('core-web.mail.from'), fn (mixed $value): bool => ! blank($value));

        // A site's published `mail` block replaces the package's whole key; read the defaults it leaves out.
        if (blank($from['local_part'] ?? null) || blank($from['domain'] ?? null)) {
            $from = array_replace((array) (require __DIR__.'/../../config/core-web.php')['mail']['from'], $from);
            $config->set('core-web.mail.from', $from);
        }

        $address = $config->get('mail.from.address');

        if (! blank($address) && $address !== self::LARAVEL_DEFAULT) {
            return;
        }

        if (blank($from['local_part'] ?? null) || blank($from['domain'] ?? null)) {
            return;
        }

        $config->set('mail.from.address', trim((string) $from['local_part']).'@'.trim((string) $from['domain']));
    }
}
