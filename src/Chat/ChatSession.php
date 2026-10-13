<?php

namespace Inly\CoreWeb\Chat;

use Illuminate\Contracts\Session\Session;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The visitor's conversations, kept in their session by agent and
 * conversation key, so an access token never reaches the browser. The hint
 * cookie only says a conversation exists for an agent, which is what lets an
 * idle page skip the transcript request.
 */
class ChatSession
{
    public function __construct(protected Session $session) {}

    public function get(string $agent, string $key): ?ChatConversation
    {
        $conversation = ChatConversation::fromArray($this->session->get($this->slot($agent, $key)));

        return $conversation?->agent === $agent ? $conversation : null;
    }

    public function put(ChatConversation $conversation, string $key): void
    {
        $this->session->put($this->slot($conversation->agent, $key), $conversation->toArray());
    }

    public function forget(string $agent, string $key): void
    {
        $this->session->forget($this->slot($agent, $key));

        if ($this->session->get($this->root($agent)) === []) {
            $this->session->forget($this->root($agent));
        }
    }

    public function has(string $agent): bool
    {
        return ! empty($this->session->get($this->root($agent)));
    }

    /**
     * The opaque value Core's per-visitor limits key on: stable for the
     * session, and not the session id, which would let anyone reading Core's
     * logs take the session over.
     */
    public function visitor(): string
    {
        return substr(hash_hmac('sha256', 'core-web-chat:'.$this->session->getId(), (string) config('app.key')), 0, 40);
    }

    /** The hint cookie for an agent: set while the session holds a conversation for it, expired once it holds none. */
    public function hint(string $agent): Cookie
    {
        $name = self::cookieName($agent);

        if (! $this->has($agent)) {
            return cookie()->forget($name);
        }

        return cookie($name, '1', (int) config('session.lifetime', 120), httpOnly: false, sameSite: 'lax');
    }

    public static function cookieName(string $agent): string
    {
        return (string) config('core-web.chat.cookie', 'core_chat').'_'.$agent;
    }

    private function root(string $agent): string
    {
        return (string) config('core-web.chat.session_key', 'core_web_chat').'.'.$agent;
    }

    /** Hashed, so a key with dots in it does not nest. */
    private function slot(string $agent, string $key): string
    {
        return $this->root($agent).'.'.substr(sha1($key), 0, 16);
    }
}
