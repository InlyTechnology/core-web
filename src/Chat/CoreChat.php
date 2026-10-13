<?php

namespace Inly\CoreWeb\Chat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Inly\CoreWeb\Client\CoreClient;
use RuntimeException;

/**
 * Core's stock chat route for one visitor (`{core-web.chat.path}/{agent}`).
 * Opening a conversation is the site's own call, with its client token;
 * everything after is the conversation's, with the access token Core
 * returned once, and the client token never travels with it. Every call
 * names the visitor in `X-Core-Client-Id`, which Core's per-visitor limits
 * key on. Any failure is a ChatException carrying Core's status.
 */
class CoreChat
{
    public const VISITOR_HEADER = 'X-Core-Client-Id';

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
        if (! $this->client->configured()) {
            throw new ChatException('Core is not configured (core-web.client).');
        }

        $path = $this->path($agent, '/conversations');

        try {
            $response = $this->client->postResponse($path, $context, [self::VISITOR_HEADER => $this->visitor], $this->timeout('read'));
        } catch (ConnectionException|RuntimeException $e) {
            throw new ChatException("Core chat unreachable on POST {$path}: {$e->getMessage()}", previous: $e);
        }

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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function call(ChatConversation $conversation, string $method, string $suffix, array $data, string $timeout): array
    {
        $path = $this->path($conversation->agent, '/conversations/'.rawurlencode($conversation->id).$suffix);
        $request = $this->conversationRequest($conversation, $timeout);

        try {
            $response = $method === 'get' ? $request->get($this->client->baseUrl().$path) : $request->post($this->client->baseUrl().$path, $data);
        } catch (ConnectionException $e) {
            throw new ChatException("Core chat unreachable on {$method} {$path}: {$e->getMessage()}", previous: $e);
        }

        return $this->decode($response, strtoupper($method).' '.$path);
    }

    protected function conversationRequest(ChatConversation $conversation, string $timeout): PendingRequest
    {
        return CoreClient::request()
            ->withToken($conversation->token)
            ->withHeaders([self::VISITOR_HEADER => $this->visitor])
            ->acceptJson()
            ->connectTimeout($this->timeout('connect'))
            ->timeout($this->timeout($timeout));
    }

    /** @return array<string, mixed> */
    protected function decode(Response $response, string $call): array
    {
        if ($response->failed()) {
            throw new ChatException("Core chat error [{$response->status()}] on {$call}", $response->status());
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
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
