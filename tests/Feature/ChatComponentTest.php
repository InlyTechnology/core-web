<?php

namespace Inly\CoreWeb\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Inly\CoreWeb\Tests\TestCase;

class ChatComponentTest extends TestCase
{
    protected array $features = ['chat'];

    public function test_it_renders_only_the_alpine_wrapper_around_the_site_s_markup(): void
    {
        $html = Blade::render('<x-core-web::chat agent="assistant" :subject="$subject" class="chat"><p x-text="error"></p></x-core-web::chat>', ['subject' => '42"\'<']);

        $this->assertStringStartsWith('<div x-data="coreChat(', trim($html));
        $this->assertStringContainsString('class="chat"><p x-text="error"></p></div>', $html);

        preg_match('/x-data="coreChat\(JSON\.parse\(\'(.*)\'\)\)"/', $html, $match);
        $this->assertSame(
            ['endpoint' => '/!/core-web/chat/assistant', 'subject' => '42"\'<', 'cookie' => 'core_chat_assistant'],
            json_decode(json_decode('"'.$match[1].'"'), true),
            'the subject cannot break out of the attribute',
        );
    }
}
