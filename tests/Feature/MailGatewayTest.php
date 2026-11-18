<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inly\CoreWeb\Mail\MailGateway;
use Inly\CoreWeb\Tests\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;

class MailGatewayTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.url', 'https://Acme.example');
        $app['config']->set('core-web.mail.gateway.token', 'core-token');
        $app['config']->set('mail.default', 'core');
        $app['config']->set('mail.from', ['address' => 'acme@transactional.inly.dev', 'name' => 'Acme']);
    }

    public function test_a_message_goes_to_core_gateway_with_the_site_token(): void
    {
        Http::fake(['gateway.inlycore.com/*' => Http::response(['id' => 'resend-1'])]);

        Mail::raw('Hello', function (Message $message): void {
            $message->to('visitor@example.com')->cc('copy@example.com')->subject('Your booking');
        });

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gateway.inlycore.com/mail/emails'
            && $request->hasHeader('Authorization', 'Bearer core-token')
            && $request->hasHeader('X-Core-Environment', 'acme.example')
            && $request->hasHeader('Idempotency-Key')
            && $request['from'] === '"Acme" <acme@transactional.inly.dev>'
            && $request['to'] === ['visitor@example.com']
            && $request['cc'] === ['copy@example.com']
            && $request['subject'] === 'Your booking'
            && $request['text'] === 'Hello');
    }

    public function test_a_site_without_a_token_does_not_send(): void
    {
        config(['core-web.mail.gateway.token' => '']);
        Http::fake();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('CORE_TOKEN');

        Mail::raw('Hello', fn (Message $message) => $message->to('visitor@example.com'));
    }

    public function test_a_refusal_fails_the_send(): void
    {
        Http::fake(['gateway.inlycore.com/*' => Http::response(['error' => 'From domain not set up'], 403)]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('core-gateway refused the message (403): From domain not set up');

        Mail::raw('Hello', fn (Message $message) => $message->to('visitor@example.com'));
    }

    public function test_the_core_mailer_is_registered_beside_the_sites_own(): void
    {
        $this->assertSame(['transport' => 'core'], config('mail.mailers.core'));
        $this->assertSame('https://gateway.inlycore.com/mail/emails', MailGateway::url());
    }
}
