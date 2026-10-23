# Chat

A site's visitors talk to Core's chat agents through the site: the browser
calls the site's relay, and the relay calls Core with the visitor's
conversation. What the package gives:

- **The transport**, `Inly\CoreWeb\Chat\CoreChat`: `start($agent, $context)`,
  `read()`, `postMessage()` and `handoff()` against
  `{CORE_URL}/api/app/chat/{agent}/conversations`. Every call authenticates
  as the site, with its client token (the `CoreClient` credentials) as
  `Authorization: Bearer`, fetched afresh and retried once on a 401. A call on
  a conversation also sends the conversation's own access token, which Core
  returns once, in `X-Core-Conversation-Token`; Core serves a conversation
  only to the client that opened it, so another site's client gets a 404, and
  revoking the client closes its conversations. Every call names the visitor in
  `X-Core-Client-Id` (a random value kept in the session, so it outlives a
  regenerated session id; never the session id or an IP), which Core's
  per-visitor limits key on. A failure is a `ChatException` with Core's
  status (`busy()` 409, `limited()` 429, `invalid()` 422, `gone()` 404; 0
  when Core never answered), `retryAfter` when Core's rate limiter said how
  long to wait, and `exhausted` when a 429 means the conversation spent its
  budget, which no wait mends.
- **The transcript**, `ChatTranscript`: `{state, messages}`, each message
  with `id`, `role` (`user` or `assistant`; any other role is left out),
  `content`, `html`, `results` and `created_at`, plus `intent` on assistant
  messages. An assistant `content` of `{"reply": …, "intent": …}` (an agent
  with structured output) is unwrapped: `content` is the reply, `intent` the
  render mode; prose is `answer`. A result card keeps `title`, `subtitle`,
  `url` and `image` when they are strings (a `url` or `image` only as a web
  address or a path) and `fields` as a flat map of strings.
- **The relay**, `/!/core-web/chat/{agent}` under Statamic's action prefix,
  so it is never statically cached: `GET` (the transcript, or
  `{state: null, messages: []}`), `POST /messages` `{message, subject?}`,
  `POST /handoff` (`{summary, subject, contact: {name, email, phone}}`, every
  value null when Core could not say: a blank form is a normal outcome) and
  `DELETE` (start over). The first message opens the conversation and keeps
  its id and token in the session, by agent and conversation key. A
  conversation Core no longer knows (expired, cleared) is forgotten and the
  next message opens a fresh one. A `core_chat_{agent}` cookie (readable by
  scripts) says the session holds a conversation for the agent, so an idle
  page fetches nothing.
- **Limits of the site's own**: messages and handoffs get
  `chat.turn_rate_limit` per minute (10) per session and address, and
  `chat.turn_rate_limit_per_address` (30) per address however many sessions
  it opens, besides the routes' general `throttle:60,1`; a session holds at most
  `chat.max_conversations` (5) per agent, and a new subject past that is
  refused as `limited` without calling Core.

Errors are `{error: …}`:

| Error | Status | When |
|---|---|---|
| `busy` | 409 | A turn is already running in the conversation |
| `limited` | 429 | A rate limit, Core's or the relay's, or the conversation cap: wait. `Retry-After` is passed through when Core's limiter sent one |
| `exhausted` | 429 | The conversation spent its budget at Core. The relay has forgotten it, so offer to start over: the next message opens a fresh one |
| `invalid` | 422 | The message or subject was refused (empty, too long, screened by Core) |
| `unavailable` | 503 | Core failed or is unreachable, and when Core's rules for the agent's context refuse what the site's `ChatContext` built (a site bug); both are reported |

Core says a conversation is used up with a 429 like its rate limits; the
relay tells them apart by the answer's `code` (`conversation_exhausted`).

The access token never reaches the browser **as long as the session lives
on the server** (`file`, `database`, `redis`, …). With the `cookie` session
driver the session, tokens included, is the encrypted cookie itself, and at
around ten conversations it outgrows a browser's 4 KB cookie limit: use a
server-side driver with chat.
- **A headless front end**: an Alpine data factory and a Blade component that
  outputs only the wrapper.

Switch it on and name the agents:

```php
return [
    'features' => ['chat' => true],
    'chat' => ['agents' => ['assistant' => App\Chat\AssistantContext::class]],
];
```

Every `chat` key the site leaves out keeps its default, so a site's file
needs only `agents`.

## The context

What an agent is told about the page comes from one small class per agent,
built server side from the `subject` the browser names. The browser never
supplies the context itself: whatever it sent would reach the agent's prompt.

```php
use Illuminate\Http\Request;
use Inly\CoreWeb\Chat\ChatContext;

class AssistantContext implements ChatContext
{
    public function context(?string $subject, Request $request): array
    {
        $record = $this->record($subject);

        return $record === null ? [] : ['record_id' => $record->core_id, 'page' => $record->url()];
    }

    // A different key starts a fresh conversation: one per record.
    public function key(?string $subject, Request $request): string
    {
        return (string) ($this->record($subject)?->getKey() ?? 'general');
    }

    // Only a record that exists names a conversation, so a visitor cannot mint subjects.
    private function record(?string $subject): ?Record
    {
        return $subject === null ? null : (Record::find($subject) ?? abort(404));
    }
}
```

`key()` runs on every call and `context()` only when a conversation opens;
both must refuse a subject the site does not know (`abort(404)`), or every
made-up subject would be a conversation of its own. The agent decides which
context keys it accepts.

Agent keys are Core's: lowercase slugs (`a-z`, `0-9`, single hyphens). A
site naming any other key in `chat.agents` fails at boot.

## The front end

```js
import Alpine from 'alpinejs'
import coreChat from '../../vendor/inly/core-web/resources/js/core-chat.js'

Alpine.data('coreChat', coreChat)
```

```blade
<x-core-web::chat agent="assistant" :subject="$record->id" class="chat">
    <template x-for="message in messages" :key="message.id">
        <div :class="message.role">
            <div x-show="message.role === 'assistant'" x-html="message.html"></div>
            <p x-show="message.role === 'user'" x-text="message.content"></p>
        </div>
    </template>
    <p x-show="error" x-text="error"></p>
    <form x-on:submit.prevent="send($refs.input.value).then((sent) => sent && ($refs.input.value = ''))">
        <textarea x-ref="input" :disabled="pending"></textarea>
        <button :disabled="pending">Send</button>
    </form>
    <button type="button" x-on:click="reset()">Start over</button>
</x-core-web::chat>
```

State: `messages`, `state`, `pending`, `error` (one of the errors above, or
`expired` when the session and its CSRF token did), `retryAfter` (seconds,
when the answer said), `handoff`. Methods: `send(text)` (shows the visitor's
message at once and resolves true or false), `reset()` (on `exhausted`, this
clears the old transcript off the page), `requestHandoff()` (fills `handoff`),
`load()` (called on init only when the hint cookie is there). CSRF comes from
a `csrf-token` meta tag, else the `XSRF-TOKEN` cookie.

Render text with `x-text`. A message's `html` is safe for `x-html`: the server
rendered it with raw HTML escaped and unsafe links dropped. A result card's
values are plain strings, for `x-text` and bound attributes.

What stays in the site: the markup and its styling, the context classes, and
what a handoff becomes (the lead form, where it is sent).

What Core must have: inly-core v29.0.0 or later (the stock chat route), the
agent's key in `chat.agents` matching its `chatRoute()`, and the site's app
client (`CORE_CLIENT_ID`) holding the
`use_public_chat` scope (or the agent's own `chatPermission()`), which every
call needs, not only opening a conversation (without it Core answers 403,
which the relay reports as `unavailable`). A turn runs while Core answers, so
the web server's read timeout (nginx's `fastcgi_read_timeout`) must outlast
`chat.timeouts.turn`.
