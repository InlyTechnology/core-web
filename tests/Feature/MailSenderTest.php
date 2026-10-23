<?php

namespace Inly\CoreWeb\Tests\Feature;

use Inly\CoreWeb\Mail\MailSender;
use Inly\CoreWeb\Tests\TestCase;

class MailSenderTest extends TestCase
{
    public function test_a_local_part_sends_from_the_transactional_domain(): void
    {
        $this->applyWith(['local_part' => 'acme'], 'hello@example.com');

        $this->assertSame('acme@transactional.inly.dev', config('mail.from.address'));
    }

    public function test_an_unset_address_is_filled_too(): void
    {
        $this->applyWith(['local_part' => 'no-reply', 'domain' => 'mail.acme.example'], null);

        $this->assertSame('no-reply@mail.acme.example', config('mail.from.address'));
    }

    public function test_an_address_the_site_set_wins(): void
    {
        $this->applyWith(['local_part' => 'acme'], 'news@acme.example');

        $this->assertSame('news@acme.example', config('mail.from.address'));
    }

    public function test_no_local_part_leaves_the_address_alone(): void
    {
        $this->applyWith([], 'hello@example.com');

        $this->assertSame('hello@example.com', config('mail.from.address'));
    }

    public function test_a_blank_domain_keeps_the_default(): void
    {
        $this->applyWith(['local_part' => 'acme', 'domain' => null], null);

        $this->assertSame('acme@transactional.inly.dev', config('mail.from.address'));
    }

    public function test_both_parts_are_read_from_the_environment(): void
    {
        putenv('MAIL_FROM_LOCAL_PART=no-reply');
        putenv('MAIL_FROM_DOMAIN=mail.acme.example');

        try {
            $from = (require __DIR__.'/../../config/core-web.php')['mail']['from'];
        } finally {
            putenv('MAIL_FROM_LOCAL_PART');
            putenv('MAIL_FROM_DOMAIN');
        }

        $this->assertSame(['local_part' => 'no-reply', 'domain' => 'mail.acme.example'], $from);
    }

    /** @param  array<string, string|null>  $from  as a site's published `mail.from` block sets it */
    private function applyWith(array $from, ?string $address): void
    {
        config(['core-web.mail' => ['from' => $from], 'mail.from.address' => $address]);

        MailSender::apply($this->app);
    }
}
