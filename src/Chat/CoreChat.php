<?php

namespace Inly\CoreWeb\Chat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Inly\CoreWeb\Client\CoreClient;
use RuntimeException;

/**
 * Core's stock chat route for one visitor (`{core-web.chat.path}/{agent}`).
 * Every call is the site's, with its client token as the bearer; a call on
 * a conversation also carries the access token Core returned once, in
 * `X-Core-Conversation-Token`, and Core serves a conversation only to the
 * client that opened it. Every call names the visitor in `X-Core-Client-Id`,
 * which Core's per-visitor limits key on. Any failure is a ChatException
 * carrying Core's status.
 */
class CoreChat
{
    public const VISITOR_HEADER = 'X-Core-Client-Id';

    public const CONVERSATION_HEADER = 'X-Core-Conversation-Token';

    public const EXHAUSTED_CODE = 'conversation_exhausted';

    public function __construct(
        protected CoreClient $client,
        protected string $visitor,
    ) {}

    /** For a visitor: an opaque value stable for them, never an IP or a session id (see ChatSession::visitor()). */
    public static function for(string $visitor): static
    {
        return new static(CoreClient::fromConfig(), $visitor);
    }

    /** @param  array<string, mixed>  $context  the agent's context payload */
    public function start(string $agent, array $context = []): ChatConversation
    {
        $path = $this->path($agent, '/conversations');
        $response = $this->send('post', $path, $context, [], 'read');

        $payload = $this->decode($response, "POST {$path}");
        $id = data_get($payload, 'conversation.id');
        $token = data_get($payload, 'access_token');

        if ((! is_string($id) && ! is_int($id)) || ! is_string($token) || (string) $id === '' || $token === '') {
            throw new ChatException("Core chat answered POST {$path} without a conversation and its token.", 502);
        }

        return new ChatConversation($agent, (string) $id, $token);
    }

    public function read(ChatConversation $conversation): ChatTranscript
    {
        return ChatTranscript::fromPayload($this->call($conversation, 'get', '', [], 'read'));
    }

    /** Runs the agent's turn: Core answers once it is done, with the whole transcript. */
    public function postMessage(ChatConversation $conversation, string $message): ChatTranscript
    {
        return ChatTranscript::fromPayload($this->call($conversation, 'post', '/messages', ['message' => $message], 'turn'));
    }

    /**
     * What the conversation tells a contact form, every value null when Core
     * could not say: a blank form is a normal outcome.
     *
     * @return array{summary: ?string, subject: ?string, contact: array{name: ?string, email: ?string, phone: ?string}}
     */
    public function handoff(ChatConversation $conversation): array
    {
        $payload = $this->call($conversation, 'post', '/handoff', [], 'turn');
        $string = fn (string $key): ?string => is_string($value = data_get($payload, $key)) && trim($value) !== '' ? trim($value) : null;

        return [
            'summary' => $string('summary'),
            'subject' => $string('subject'),
            'contact' => ['name' => $string('contact.name'), 'email' => $string('contact.email'), 'phone' => $string('contact.phone')],
        ];
    }

    /**
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function call(ChatConversation $conversation, string $method, string $suffix, array $data, string $timeout): array
    {
        $path = $this->path($conversation->agent, '/conversations/'.rawurlencode($conversation->id).$suffix);
        $response = $this->send($method, $path, $data, [self::CONVERSATION_HEADER => $conversation->token], $timeout);

        return $this->decode($response, strtoupper($method).' '.$path);
    }

    /**
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function send(string $method, string $path, array $data, array $headers, string $timeout): Response
    {
        if (! $this->client->configured()) {
            throw new ChatException('Core is not configured (core-web.client).');
        }

        try {
            return $this->client->sendResponse($method, $path, $data, $headers + [self::VISITOR_HEADER => $this->visitor], $this->timeout($timeout), $this->timeout('connect'));
        } catch (ConnectionException|RuntimeException $e) {
            throw new ChatException('Core chat unreachable on '.strtoupper($method)." {$path}: {$e->getMessage()}", previous: $e);
        }
    }

    /** @return array<string, mixed> */
    protected function decode(Response $response, string $call): array
    {
        if ($response->failed()) {
            $retryAfter = $response->header('Retry-After');

            throw new ChatException(
                "Core chat error [{$response->status()}] on {$call}",
                $response->status(),
                retryAfter: ctype_digit($retryAfter) ? (int) $retryAfter : null,
                exhausted: $response->status() === 429 && self::exhausted($response),
            );
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /** Core answers a spent conversation budget with a 429 like its rate limits, told apart only by `code`. */
    protected static function exhausted(Response $response): bool
    {
        return $response->json('code') === self::EXHAUSTED_CODE;
    }

    protected function path(string $agent, string $suffix): string
    {
        return '/'.trim((string) config('core-web.chat.path', 'api/app/chat'), '/').'/'.rawurlencode($agent).$suffix;
    }

    protected function timeout(string $kind): int
    {
        return (int) config("core-web.chat.timeouts.{$kind}", ['connect' => 5, 'read' => 8, 'turn' => 60][$kind]);
    }
}
