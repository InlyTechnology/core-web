<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inly\CoreWeb\Mail\MailGateway;
use Inly\CoreWeb\Tests\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Part\DataPart;

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
            && str_ends_with($request->header('Idempotency-Key')[0] ?? '', '@transactional.inly.dev')
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

    public function test_headers_and_attachments_go_as_resends_fields(): void
    {
        Http::fake(['gateway.inlycore.com/*' => Http::response(['id' => 'resend-1'])]);

        Mail::raw('Hello', function (Message $message): void {
            $message->to('visitor@example.com')->returnPath('bounce@transactional.inly.dev')
                ->attachData('a,b', 'rows.csv', ['mime' => 'text/csv'])
                ->getSymfonyMessage()->getHeaders()->addTextHeader('X-Entity-Ref-ID', 'Booking 1');
        });

        Http::assertSent(fn (Request $request): bool => $request['headers']['X-Entity-Ref-ID'] === 'Booking 1'
            && ! isset($request['headers']['Return-Path'])
            && $request['attachments'] === [['filename' => 'rows.csv', 'content' => base64_encode('a,b'), 'content_type' => 'text/csv']]);
    }

    public function test_an_image_embedded_by_name_is_referenced_by_its_content_id(): void
    {
        Http::fake(['gateway.inlycore.com/*' => Http::response(['id' => 'resend-1'])]);

        Mail::raw('Hello', fn (Message $message) => $message->to('visitor@example.com')->getSymfonyMessage()
            ->html('<p><img src="cid:logo.png"></p>')
            ->addPart((new DataPart('png', 'logo.png', 'image/png'))->asInline()));

        Http::assertSent(function (Request $request): bool {
            $logo = collect($request['attachments'])->firstWhere('filename', 'logo.png');

            return filled($logo['content_id'] ?? null)
                && $logo['content_id'] !== 'logo.png'
                && str_contains($request['html'], 'cid:'.$logo['content_id']);
        });
    }

    public function test_an_address_in_to_and_cc_stays_in_to(): void
    {
        Http::fake(['gateway.inlycore.com/*' => Http::response(['id' => 'resend-1'])]);

        Mail::raw('Hello', fn (Message $message) => $message->to('visitor@example.com')->cc('visitor@example.com'));

        Http::assertSent(fn (Request $request): bool => $request['to'] === ['visitor@example.com']);
    }

    public function test_a_message_with_only_copies_is_refused(): void
    {
        Http::fake();

        try {
            Mail::raw('Hello', fn (Message $message) => $message->bcc(['one@example.com', 'two@example.com']));
            $this->fail('The message was sent.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('at least one To address', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_an_unreachable_gateway_fails_the_send(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('core-gateway could not be reached');

        Mail::raw('Hello', fn (Message $message) => $message->to('visitor@example.com'));
    }

    public function test_the_default_mailer_is_left_alone_and_a_sites_own_core_mailer_is_kept(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.core' => ['transport' => 'log']]);

        MailGateway::register($this->app);

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame(['transport' => 'log'], config('mail.mailers.core'));
    }

    public function test_a_published_mail_block_without_a_gateway_keeps_the_default_url_and_the_token(): void
    {
        putenv('CORE_TOKEN=env-token');

        try {
            config(['core-web.mail' => ['from' => ['local_part' => 'acme']]]);
            MailGateway::register($this->app);
            $this->assertSame('https://gateway.inlycore.com/mail/emails', MailGateway::url());
            $this->assertSame('env-token', config('core-web.mail.gateway.token'));

            config(['core-web.mail.gateway' => ['url' => 'https://gateway.example']]);
            MailGateway::register($this->app);
            $this->assertSame('https://gateway.example/mail/emails', MailGateway::url());
            $this->assertSame('env-token', config('core-web.mail.gateway.token'));
        } finally {
            putenv('CORE_TOKEN');
        }
    }
}
