<?php

namespace Inly\CoreWeb\Chat;

/** A conversation with one of Core's agents and its access token, which Core returns once and the site keeps server side. */
final class ChatConversation
{
    public function __construct(
        public readonly string $agent,
        public readonly string $id,
        public readonly string $token,
    ) {}

    /** @return array{agent: string, id: string, token: string} */
    public function toArray(): array
    {
        return ['agent' => $this->agent, 'id' => $this->id, 'token' => $this->token];
    }

    /** @param  mixed  $stored  what toArray() gave, as read back from the session */
    public static function fromArray(mixed $stored): ?self
    {
        if (! is_array($stored)) {
            return null;
        }

        $values = array_map(fn (mixed $value) => is_string($value) ? $value : '', [$stored['agent'] ?? null, $stored['id'] ?? null, $stored['token'] ?? null]);

        return in_array('', $values, true) ? null : new self(...$values);
    }
}
