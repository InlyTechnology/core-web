<?php

namespace Inly\CoreWeb\Tests\Feature\Content;

use Illuminate\Support\Facades\Route;
use Inly\CoreWeb\Tests\TestCase;

/** The feed registers nothing until a site switches it on. */
class ContentFeedOffTest extends TestCase
{
    protected array $features = ['content', 'drafts'];

    public function test_the_feed_routes_are_absent_while_it_is_off(): void
    {
        $this->assertFalse(Route::has('core-web.content.feed.index'));
        $this->assertFalse(Route::has('core-web.content.feed.show'));
    }
}
