<?php

namespace Inly\CoreWeb\Chat;

use Illuminate\Support\Str;

/**
 * A conversation's transcript as a page renders it. Every message carries
 * `html`, its Markdown rendered with raw HTML escaped and unsafe links
 * dropped, so a page may print it unescaped; assistant messages carry the
 * `intent` a structured reply names (`answer` for prose) and the result
 * cards, narrowed to the keys Core documents.
 */
final class ChatTranscript
{
    /** The string keys a result card may carry. */
    private const RESULT_KEYS = ['title', 'subtitle', 'url', 'image'];

    /** @param  list<array<string, mixed>>  $messages */
    public function __construct(
        public readonly ?string $state,
        public readonly array $messages,
    ) {}

    /** No conversation yet. */
    public static function empty(): self
    {
        return new self(null, []);
    }

    /** @param  array<string, mixed>  $payload  Core's `{conversation, messages}` */
    public static function fromPayload(array $payload): self
    {
        $state = data_get($payload, 'conversation.state');
        $messages = [];

        foreach ((array) ($payload['messages'] ?? []) as $message) {
            if (is_array($message) && ($normalized = self::message($message)) !== null) {
                $messages[] = $normalized;
            }
        }

        return new self(is_string($state) && $state !== '' ? $state : null, $messages);
    }

    /** @return array{state: ?string, messages: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['state' => $this->state, 'messages' => $this->messages];
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null
     */
    private static function message(array $message): ?array
    {
        $role = $message['role'] ?? null;

        if ($role !== 'user' && $role !== 'assistant') {
            return null;
        }

        $content = self::string($message['content'] ?? null) ?? '';
        $intent = null;

        if ($role === 'assistant') {
            [$content, $intent] = self::structured($content);
        }

        return [
            'id' => self::string($message['id'] ?? null) ?? '',
            'role' => $role,
            'content' => $content,
            'html' => self::html($content),
            ...($role === 'assistant' ? ['intent' => $intent] : []),
            'results' => $role === 'assistant' ? self::results($message['results'] ?? null) : [],
            'created_at' => self::string($message['created_at'] ?? null),
        ];
    }

    /**
     * An agent with structured output puts `{"reply": …, "intent": …}` in
     * `content`; anything else is prose.
     *
     * @return array{0: string, 1: string}
     */
    private static function structured(string $content): array
    {
        $decoded = str_starts_with(ltrim($content), '{') ? json_decode($content, true) : null;

        if (! is_array($decoded) || (! array_key_exists('reply', $decoded) && ! array_key_exists('intent', $decoded))) {
            return [$content, 'answer'];
        }

        $intent = self::string($decoded['intent'] ?? null);

        return [self::string($decoded['reply'] ?? null) ?? '', $intent !== null && $intent !== '' ? $intent : 'answer'];
    }

    public static function html(string $markdown): string
    {
        return $markdown === '' ? '' : trim(Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]));
    }

    /** @return list<array<string, mixed>> */
    private static function results(mixed $results): array
    {
        $cards = [];

        foreach (is_array($results) ? $results : [] as $result) {
            if (! is_array($result) || ! is_string($result['title'] ?? null) || trim($result['title']) === '') {
                continue;
            }

            $card = [];

            foreach (self::RESULT_KEYS as $key) {
                if (is_string($result[$key] ?? null)) {
                    $card[$key] = $result[$key];
                }
            }

            foreach (['url', 'image'] as $link) {
                if (isset($card[$link]) && ! self::safeUrl($card[$link])) {
                    unset($card[$link]);
                }
            }

            $card['fields'] = self::fields($result['fields'] ?? null);
            $cards[] = $card;
        }

        return $cards;
    }

    /**
     * A flat map of string values; a number is read as its string, anything else is dropped.
     *
     * @return array<string, string>
     */
    private static function fields(mixed $fields): array
    {
        $flat = [];

        foreach (is_array($fields) ? $fields : [] as $key => $value) {
            if (is_string($key) && (is_string($value) || is_int($value) || is_float($value))) {
                $flat[$key] = (string) $value;
            }
        }

        return $flat;
    }

    /** A page puts a card's url in an href or src, so only a web address or a path on the site passes, never `//host`. */
    private static function safeUrl(string $url): bool
    {
        return preg_match('~^(https?://|/(?![/\\\\]))~i', $url) === 1;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
