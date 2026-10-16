<?php

namespace Inly\CoreWeb\Tests\Unit;

use Inly\CoreWeb\Chat\ChatTranscript;
use Inly\CoreWeb\Tests\TestCase;

/** A transcript as a page may render it: escaped HTML, structured replies unwrapped, result cards narrowed. */
class ChatTranscriptTest extends TestCase
{
    /** @param  array<string, mixed>  $message */
    private static function one(array $message): array
    {
        return ChatTranscript::fromPayload(['messages' => [$message]])->messages[0];
    }

    public function test_html_in_content_comes_out_escaped_and_unsafe_links_are_dropped(): void
    {
        $message = self::one(['id' => 'm', 'role' => 'assistant', 'content' => "**Hi** <script>alert(1)</script>\n\n[x](javascript:alert(1)) [ok](https://example.com)"]);

        $this->assertStringNotContainsString('<script>', $message['html']);
        $this->assertStringContainsString('&lt;script&gt;', $message['html']);
        $this->assertStringContainsString('<strong>Hi</strong>', $message['html']);
        $this->assertStringNotContainsString('javascript:', $message['html']);
        $this->assertStringContainsString('<a href="https://example.com">ok</a>', $message['html']);
    }

    public function test_a_visitor_s_message_is_escaped_too(): void
    {
        $message = self::one(['id' => 'm', 'role' => 'user', 'content' => '<img src=x onerror=alert(1)>']);

        $this->assertStringNotContainsString('<img', $message['html']);
        $this->assertSame([], $message['results']);
        $this->assertArrayNotHasKey('intent', $message);
    }

    public function test_a_structured_reply_is_unwrapped(): void
    {
        $message = self::one(['id' => 'm', 'role' => 'assistant', 'content' => json_encode(['reply' => 'Shall I put you in touch?', 'intent' => 'handoff'])]);

        $this->assertSame('Shall I put you in touch?', $message['content']);
        $this->assertSame('<p>Shall I put you in touch?</p>', $message['html']);
        $this->assertSame('handoff', $message['intent']);
    }

    public function test_prose_and_json_of_another_shape_are_answers(): void
    {
        $this->assertSame('answer', self::one(['role' => 'assistant', 'content' => 'Plain prose'])['intent']);

        $other = self::one(['role' => 'assistant', 'content' => '{"price": 100}']);
        $this->assertSame('{"price": 100}', $other['content']);
        $this->assertSame('answer', $other['intent']);
    }

    public function test_results_keep_only_known_string_keys_and_safe_links(): void
    {
        $results = self::one(['role' => 'assistant', 'content' => 'Two', 'results' => [
            ['title' => 'A', 'subtitle' => 'Sub', 'url' => '/a', 'image' => 'https://cdn.example.com/a.jpg', 'handle' => 'x', 'fields' => ['id' => 42, 'name' => 'A', 'nested' => ['no'], 'flag' => true]],
            ['title' => 'B', 'url' => 'javascript:alert(1)', 'image' => ['no'], 'subtitle' => 5],
            ['title' => 'C', 'url' => '//evil.example/a', 'image' => '/\\evil.example/a.jpg'],
            ['subtitle' => 'no title'],
            'not a card',
        ]])['results'];

        $this->assertSame([
            ['title' => 'A', 'subtitle' => 'Sub', 'url' => '/a', 'image' => 'https://cdn.example.com/a.jpg', 'fields' => ['id' => '42', 'name' => 'A']],
            ['title' => 'B', 'fields' => []],
            ['title' => 'C', 'fields' => []],
        ], $results);
    }

    public function test_messages_of_other_roles_are_left_out(): void
    {
        $transcript = ChatTranscript::fromPayload(['conversation' => ['state' => 'processing'], 'messages' => [
            ['id' => '1', 'role' => 'system', 'content' => 'secret prompt'],
            ['id' => '2', 'role' => 'tool', 'content' => '{}'],
            ['id' => '3', 'role' => 'user', 'content' => 'Hej'],
        ]]);

        $this->assertSame('processing', $transcript->state);
        $this->assertSame(['3'], array_column($transcript->messages, 'id'));
    }
}
