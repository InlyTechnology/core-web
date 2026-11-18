<?php

namespace Inly\CoreWeb\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;
use Throwable;

/**
 * The `core` mail transport: each message goes to core-gateway as Resend's JSON with the site's CORE_TOKEN, and
 * core-services sends it with the key restricted to the From domain, so the site holds no Resend key. The same wire
 * format as Core's own `core` mailer. See docs/mail-sender.md (Sending through core-gateway).
 */
final class GatewayMailTransport extends AbstractTransport
{
    public const TRANSPORT = 'core';

    public const ENVIRONMENT_HEADER = 'X-Core-Environment';

    /** Headers Resend takes from the payload's own fields rather than from `headers`, and Return-Path, which core-gateway refuses there. */
    private const FIELD_HEADERS = ['from', 'to', 'cc', 'bcc', 'reply-to', 'sender', 'return-path', 'subject', 'content-type'];

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->token === '') {
            throw new TransportException('The core mailer needs CORE_TOKEN to send.');
        }

        $email = MessageConverter::toEmail($message->getOriginalMessage());
        $payload = $this->payload($email, $message->getEnvelope());

        // One message is one Resend call, so a message with only copies has no one to send it to.
        if (! isset($payload['to'])) {
            throw new TransportException('The core mailer sends a message to at least one To address; copies and blind copies go with it.');
        }

        $id = $this->post($payload, $message->getMessageId());

        if ($id !== null) {
            $email->getHeaders()->addTextHeader('X-Resend-Email-ID', $id);
            $message->setMessageId($id);
        }
    }

    /**
     * A retry after a timeout carries the same key, so Resend sends the message once even when the first attempt
     * reached it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(array $payload, string $idempotencyKey): ?string
    {
        try {
            $response = Http::withToken($this->token)
                ->withHeaders(array_filter([
                    self::ENVIRONMENT_HEADER => $this->environment,
                    'Idempotency-Key' => $idempotencyKey,
                ]))
                ->acceptJson()
                // Two attempts of 20 s stay inside a queued mail's minute.
                ->timeout(20)
                ->retry(2, 500, fn (Throwable $exception) => $exception instanceof ConnectionException, throw: false)
                ->post($this->url, $payload);
        } catch (ConnectionException $exception) {
            throw new TransportException(sprintf('core-gateway could not be reached: %s', $exception->getMessage()), 0, $exception);
        }

        if ($response->failed()) {
            $reason = $response->json('error') ?? $response->json('message') ?? Str::limit($response->body(), 300);

            throw new TransportException(sprintf('core-gateway refused the message (%d): %s', $response->status(), is_string($reason) ? $reason : json_encode($reason)), $response->status());
        }

        return is_string($id = $response->json('id')) ? $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Email $email, Envelope $envelope): array
    {
        $headers = [];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (! in_array($name, self::FIELD_HEADERS, true)) {
                // Resend encodes the headers itself, so an unstructured value goes as the text it is.
                $headers[$header->getName()] = $header instanceof UnstructuredHeader ? $header->getValue() : $header->getBodyAsString();
            }
        }

        return array_filter([
            'from' => ($email->getFrom()[0] ?? $envelope->getSender())->toString(),
            'to' => $this->addresses($this->recipients($email, $envelope)),
            'cc' => $this->addresses($email->getCc()),
            'bcc' => $this->addresses($email->getBcc()),
            'reply_to' => $this->addresses($email->getReplyTo()),
            'subject' => $email->getSubject(),
            'html' => $this->html($email),
            'text' => is_resource($text = $email->getTextBody()) ? stream_get_contents($text) : $text,
            'headers' => $headers,
            'attachments' => $this->attachments($email),
        ], fn (mixed $value) => $value !== null && $value !== [] && $value !== '');
    }

    public function __toString(): string
    {
        return self::TRANSPORT;
    }

    /**
     * @return list<array<string, string>>
     */
    private function attachments(Email $email): array
    {
        $html = $email->getHtmlBody();
        $referenced = $this->referencedParts($email, is_string($html) ? $html : null);
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $contentType = $headers->get('Content-Type')?->getBody() ?? 'application/octet-stream';
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename') ?? $attachment->getFilename() ?? 'attachment';

            $item = [
                'filename' => $filename,
                'content' => base64_encode($attachment->getBody()),
                'content_type' => $contentType,
            ];

            if (in_array($attachment, $referenced, true) || $headers->getHeaderBody('Content-Disposition') === 'inline') {
                $item['content_id'] = $attachment->getContentId();
            }

            $attachments[] = $item;
        }

        return $attachments;
    }

    /** The HTML body with each embedded part referenced by its content id, which a body may also name by file name. */
    private function html(Email $email): ?string
    {
        $html = $email->getHtmlBody();
        $html = is_resource($html) ? stream_get_contents($html) : $html;

        if (! is_string($html)) {
            return null;
        }

        $replacements = [];

        foreach ($this->referencedParts($email, $html) as $name => $part) {
            $replacements['cid:'.$name] = 'cid:'.$part->getContentId();
        }

        // strtr() matches the longest name first and never replaces inside text it already substituted.
        return $replacements === [] ? $html : strtr($html, $replacements);
    }

    /**
     * @return array<string, DataPart>
     */
    private function referencedParts(Email $email, ?string $html): array
    {
        if ($html === null) {
            return [];
        }

        preg_match_all('/<\w+\s+[^>]*(?:src|background)\s*=\s*(?:(["\'])cid:(.+?)\1|cid:([^>\s]+))/i', $html, $matches);
        $names = array_filter(array_unique([...$matches[2], ...$matches[3]]));
        $parts = [];

        foreach ($email->getAttachments() as $attachment) {
            foreach ($names as $name) {
                if (! isset($parts[$name]) && ($name === $attachment->getName() || ($attachment->hasContentId() && $name === $attachment->getContentId()))) {
                    $parts[$name] = $attachment;

                    continue 2;
                }
            }
        }

        return $parts;
    }

    /**
     * The envelope's recipients that are not only copied or blind-copied: an address in To stays there even when it is
     * also copied.
     *
     * @return list<Address>
     */
    private function recipients(Email $email, Envelope $envelope): array
    {
        $to = array_map(fn (Address $address) => $address->getAddress(), $email->getTo());
        $copied = array_map(fn (Address $address) => $address->getAddress(), [...$email->getCc(), ...$email->getBcc()]);

        $recipients = [];

        foreach ($envelope->getRecipients() as $address) {
            if (in_array($address->getAddress(), $to, true) || ! in_array($address->getAddress(), $copied, true)) {
                // The envelope lists an address once for each field it is in.
                $recipients[$address->getAddress()] ??= $address;
            }
        }

        return array_values($recipients);
    }

    /**
     * @param  array<Address>  $addresses
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $address) => $address->toString(), $addresses));
    }
}
