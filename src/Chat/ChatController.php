<?php

namespace Inly\CoreWeb\Chat;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * The site's relay to Core's stock chat route, for the agents named in
 * core-web.chat.agents. The browser names an agent and, at most, a subject;
 * what the agent is told comes from the agent's ChatContext, and the
 * conversation's access token stays in the session. A conversation Core no
 * longer knows is forgotten, and the next message opens a fresh one.
 */
class ChatController
{
    /** Core's agent keys: lowercase slugs. */
    public const AGENT_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public const TURN_LIMITER = 'core-web-chat-turn';

    public function show(Request $request, string $agent): JsonResponse
    {
        [$context, $key] = $this->resolve($request, $agent);

        if ($context === null) {
            return $this->invalid();
        }

        $session = new ChatSession($request->session());

        if (($conversation = $session->get($agent, $key)) === null) {
            return $this->respond($session, $agent, ChatTranscript::empty()->toArray());
        }

        return $this->relay($session, $agent, $key, function (CoreChat $chat) use ($session, $conversation, $key) {
            try {
                return $chat->read($conversation)->toArray();
            } catch (ChatException $e) {
                if (! $e->gone()) {
                    throw $e;
                }

                $session->forget($conversation->agent, $key);

                return ChatTranscript::empty()->toArray();
            }
        });
    }

    public function message(Request $request, string $agent): JsonResponse
    {
        [$context, $key] = $this->resolve($request, $agent);
        $valid = Validator::make($request->all(), ['message' => ['required', 'string', 'max:'.(int) config('core-web.chat.max_message_length', 10000)]]);

        if ($context === null || $valid->fails()) {
            return $this->invalid();
        }

        $session = new ChatSession($request->session());
        $message = trim((string) $request->input('message'));

        return $this->relay($session, $agent, $key, function (CoreChat $chat) use ($session, $agent, $key, $context, $message) {
            $open = function () use ($chat, $session, $agent, $key, $context): ChatConversation {
                if ($session->count($agent) >= (int) config('core-web.chat.max_conversations', 5)) {
                    throw new ChatException("The session holds the most conversations it may with [{$agent}].", 429);
                }

                try {
                    $conversation = $chat->start($agent, $context());
                } catch (ChatException $e) {
                    // Core's rules for the agent's context refused what the site's ChatContext built: the site's bug, not the visitor's.
                    throw $e->invalid() ? new ChatException("Core refused the context of [{$agent}]: {$e->getMessage()}", previous: $e) : $e;
                }

                $session->put($conversation, $key);

                return $conversation;
            };

            if (($conversation = $session->get($agent, $key)) === null) {
                return $chat->postMessage($open(), $message)->toArray();
            }

            try {
                return $chat->postMessage($conversation, $message)->toArray();
            } catch (ChatException $e) {
                if (! $e->gone()) {
                    throw $e;
                }

                $session->forget($agent, $key);

                return $chat->postMessage($open(), $message)->toArray();
            }
        });
    }

    public function handoff(Request $request, string $agent): JsonResponse
    {
        [$context, $key] = $this->resolve($request, $agent);

        if ($context === null) {
            return $this->invalid();
        }

        $session = new ChatSession($request->session());
        $blank = ['summary' => null, 'subject' => null, 'contact' => ['name' => null, 'email' => null, 'phone' => null]];

        if (($conversation = $session->get($agent, $key)) === null) {
            return $this->respond($session, $agent, $blank);
        }

        return $this->relay($session, $agent, $key, function (CoreChat $chat) use ($session, $conversation, $key, $blank) {
            try {
                return $chat->handoff($conversation);
            } catch (ChatException $e) {
                if (! $e->gone()) {
                    throw $e;
                }

                $session->forget($conversation->agent, $key);

                return $blank;
            }
        });
    }

    public function forget(Request $request, string $agent): JsonResponse
    {
        [$context, $key] = $this->resolve($request, $agent);

        if ($context === null) {
            return $this->invalid();
        }

        $session = new ChatSession($request->session());
        $session->forget($agent, $key);

        return $this->respond($session, $agent, ChatTranscript::empty()->toArray());
    }

    /**
     * The agent's context (deferred: only a new conversation reads it) and the conversation key, or a null context
     * for a subject that is not a string. An agent the site does not name is a 404.
     *
     * @return array{0: (Closure(): array<string, mixed>)|null, 1: string}
     */
    protected function resolve(Request $request, string $agent): array
    {
        $agents = (array) config('core-web.chat.agents', []);

        abort_unless(array_key_exists($agent, $agents), 404);

        $subject = $request->input('subject');

        if ($subject !== null && (! is_scalar($subject) || mb_strlen((string) $subject) > 255)) {
            return [null, ''];
        }

        $subject = $subject === null || $subject === '' ? null : (string) $subject;

        if (($class = $agents[$agent]) === null) {
            return [fn (): array => [], ''];
        }

        $context = app($class);

        if (! $context instanceof ChatContext) {
            throw new InvalidArgumentException("core-web.chat.agents.{$agent} must name a ".ChatContext::class.", not [{$class}].");
        }

        return [fn (): array => $context->context($subject, $request), $context->key($subject, $request)];
    }

    /**
     * A conversation that spent its budget is forgotten, so the visitor's next message opens a fresh one.
     *
     * @param  Closure(CoreChat): array<string, mixed>  $call
     */
    protected function relay(ChatSession $session, string $agent, string $key, Closure $call): JsonResponse
    {
        try {
            return $this->respond($session, $agent, $call(CoreChat::for($session->visitor())));
        } catch (ChatException $e) {
            [$error, $status] = match (true) {
                $e->busy() => ['busy', 409],
                $e->exhausted => ['exhausted', 429],
                $e->limited() => ['limited', 429],
                $e->invalid() => ['invalid', 422],
                default => ['unavailable', 503],
            };

            if ($status === 503) {
                report($e);
            }

            if ($e->exhausted) {
                $session->forget($agent, $key);
            }

            $response = $this->respond($session, $agent, ['error' => $error], $status);

            return $e->retryAfter === null ? $response : $response->header('Retry-After', (string) $e->retryAfter);
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function respond(ChatSession $session, string $agent, array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)
            ->header('Cache-Control', 'no-store, private')
            ->withCookie($session->hint($agent));
    }

    protected function invalid(): JsonResponse
    {
        return response()->json(['error' => 'invalid'], 422)->header('Cache-Control', 'no-store, private');
    }
}
