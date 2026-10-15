<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inly\CoreWeb\Chat\ChatConversation;
use Inly\CoreWeb\Chat\ChatException;
use Inly\CoreWeb\Chat\CoreChat;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;

/** The transport to Core's stock chat route: the client token on every call, the conversation token beside it, the visitor header, and failures as statuses. */
class CoreChatTest extends TestCase
{
    use FakesCore;

    protected array $features = ['chat'];

    private const TRANSCRIPT = [
        'conversation' => ['id' => 'c1', 'state' => 'completed'],
        'messages' => [
            ['id' => 'm1', 'role' => 'user', 'content' => 'Hej', 'created_at' => '2026-10-13T10:00:00Z'],
            ['id' => 'm2', 'role' => 'assistant', 'content' => 'Hello', 'created_at' => '2026-10-13T10:00:02Z'],
        ],
    ];

    private function conversation(): ChatConversation
    {
        return new ChatConversation('assistant', 'c1', 'conversation-token');
    }

    private function fakeChat(): void
    {
        $this->fakeCore([
            'core.test/api/app/chat/assistant/conversations' => Http::response(['conversation' => ['id' => 'c1', 'state' => 'idle'], 'access_token' => 'conversation-token'], 201),
            'core.test/api/app/chat/assistant/conversations/c1/messages' => Http::response(self::TRANSCRIPT),
            'core.test/api/app/chat/assistant/conversations/c1/handoff' => Http::response(['summary' => ' Wants a quote ', 'contact' => ['email' => 'a@example.com', 'phone' => 46]]),
            'core.test/api/app/chat/assistant/conversations/c1' => Http::response(self::TRANSCRIPT),
        ]);
    }

    public function test_opening_a_conversation_sends_the_context_with_the_client_token(): void
    {
        $this->fakeChat();

        $conversation = CoreChat::for('visitor-1')->start('assistant', ['record' => '42']);

        $this->assertEquals(new ChatConversation('assistant', 'c1', 'conversation-token'), $conversation);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://core.test/api/app/chat/assistant/conversations'
            && $request->method() === 'POST'
            && $request['record'] === '42'
            && $request->hasHeader('Authorization', 'Bearer token')
            && $request->hasHeader('X-Core-Client-Id', 'visitor-1'));
    }

    public function test_the_conversation_s_calls_carry_the_client_token_and_its_access_token_in_a_header(): void
    {
        $this->fakeChat();
        $chat = CoreChat::for('visitor-1');

        $chat->read($this->conversation());
        $chat->postMessage($this->conversation(), 'Hej');
        $chat->handoff($this->conversation());

        $calls = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->filter(fn (Request $request) => str_contains($request->url(), '/conversations/c1'));

        $this->assertSame(['GET', 'POST', 'POST'], $calls->map(fn (Request $request) => $request->method())->values()->all());
        $this->assertTrue($calls->every(fn (Request $request) => $request->header('Authorization') === ['Bearer token']
            && $request->header('X-Core-Conversation-Token') === ['conversation-token']
            && $request->hasHeader('X-Core-Client-Id', 'visitor-1')));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/c1/messages') && $request['message'] === 'Hej');
    }

    public function test_opening_a_conversation_sends_no_conversation_token(): void
    {
        $this->fakeChat();

        CoreChat::for('v')->start('assistant');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/conversations') && ! $request->hasHeader('X-Core-Conversation-Token'));
    }

    public function test_a_conversation_call_fetches_a_fresh_client_token_once_on_a_401(): void
    {
        $this->fakeCore([
            'core.test/api/app/chat/assistant/conversations/c1' => fn (Request $request) => $request->hasHeader('Authorization', 'Bearer token')
                ? Http::response(self::TRANSCRIPT)
                : Http::response(['message' => 'Unauthenticated.'], 401),
        ]);
        Cache::put('core_web_access_token', 'stale', 3600);

        $this->assertSame('completed', CoreChat::for('v')->read($this->conversation())->state);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/conversations/c1') && $request->hasHeader('Authorization', 'Bearer stale'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/conversations/c1') && $request->hasHeader('Authorization', 'Bearer token')
            && $request->hasHeader('X-Core-Conversation-Token', 'conversation-token'));
    }

    public function test_a_conversation_call_still_refused_after_a_fresh_token_is_a_401(): void
    {
        $this->fakeCore(['core.test/api/app/chat/assistant/conversations/c1/messages' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        try {
            CoreChat::for('v')->postMessage($this->conversation(), 'Hej');
            $this->fail('expected a 401');
        } catch (ChatException $e) {
            $this->assertSame(401, $e->status);
        }

        $this->assertCount(2, collect(Http::recorded())->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/c1/messages')));
    }

    public function test_a_turn_answers_the_normalized_transcript(): void
    {
        $this->fakeChat();

        $transcript = CoreChat::for('v')->postMessage($this->conversation(), 'Hej');

        $this->assertSame('completed', $transcript->state);
        $this->assertSame(['m1', 'm2'], array_column($transcript->messages, 'id'));
        $this->assertSame('<p>Hello</p>', $transcript->messages[1]['html']);
        $this->assertSame('answer', $transcript->messages[1]['intent']);
        $this->assertArrayNotHasKey('intent', $transcript->messages[0]);
    }

    public function test_the_handoff_keeps_only_strings_and_fills_the_rest_with_null(): void
    {
        $this->fakeChat();

        $this->assertSame([
            'summary' => 'Wants a quote',
            'subject' => null,
            'contact' => ['name' => null, 'email' => 'a@example.com', 'phone' => null],
        ], CoreChat::for('v')->handoff($this->conversation()));
    }

    public function test_a_refusal_carries_core_s_status(): void
    {
        $this->fakeCore([
            'core.test/api/app/chat/assistant/conversations/c1/messages' => Http::response(['message' => 'busy'], 409),
            'core.test/api/app/chat/assistant/conversations/c1' => Http::response(['message' => 'gone'], 404),
        ]);

        foreach ([409 => fn (CoreChat $chat) => $chat->postMessage($this->conversation(), 'Hej'), 404 => fn (CoreChat $chat) => $chat->read($this->conversation())] as $status => $call) {
            try {
                $call(CoreChat::for('v'));
                $this->fail("expected a {$status}");
            } catch (ChatException $e) {
                $this->assertSame($status, $e->status);
            }
        }
    }

    public function test_an_answer_without_the_token_is_a_failure(): void
    {
        $this->fakeCore(['core.test/api/app/chat/assistant/conversations' => Http::response(['conversation' => ['id' => 'c1']], 201)]);

        $this->expectException(ChatException::class);
        CoreChat::for('v')->start('assistant');
    }

    public function test_an_unconfigured_client_fails_without_a_call(): void
    {
        Http::fake();

        foreach ([fn (CoreChat $chat) => $chat->start('assistant'), fn (CoreChat $chat) => $chat->read($this->conversation())] as $call) {
            try {
                $call(CoreChat::for('v'));
                $this->fail('expected a failure');
            } catch (ChatException $e) {
                $this->assertSame(0, $e->status);
            }
        }

        Http::assertNothingSent();
    }
}
