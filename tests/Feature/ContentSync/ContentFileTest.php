<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Inly\CoreWeb\ContentSync\ContentFile;
use Inly\CoreWeb\Tests\TestCase;

class ContentFileTest extends TestCase
{
    public function test_a_term_s_stamps_are_no_change_in_any_site(): void
    {
        $local = "title: News\nupdated_at: 1\nlocalizations:\n  other:\n    title: Nyheter\n    updated_at: 1\n";
        $remote = "localizations:\n  other:\n    updated_at: 2\n    title: Nyheter\nupdated_at: 2\ntitle: News\n";

        $this->assertTrue(ContentFile::same('taxonomies/tags/news.yaml', $remote, $local));
        $this->assertFalse(ContentFile::same('taxonomies/tags/news.yaml', str_replace('Nyheter', 'Nytt', $remote), $local));
    }

    public function test_a_term_keeps_its_blueprint_and_localizations_last(): void
    {
        $local = "title: News\nlocalizations:\n  other:\n    title: Nyheter\n";
        $remote = "blueprint: tag\nlocalizations:\n  other:\n    title: Nytt\ntitle: News\ncolor: red\n";

        $this->assertSame("title: News\ncolor: red\nblueprint: tag\nlocalizations:\n  other:\n    title: Nytt\n", ContentFile::merge('terms', 'taxonomies/tags/news.yaml', $remote, $local));
    }

    public function test_tree_order_is_content_but_branch_key_order_is_not(): void
    {
        $local = "tree:\n  -\n    entry: a\n    children:\n      -\n        entry: b\n  -\n    entry: c\n";
        $reordered = "tree:\n  -\n    children:\n      -\n        entry: b\n    entry: a\n  -\n    entry: c\n";
        $moved = "tree:\n  -\n    entry: c\n  -\n    entry: a\n    children:\n      -\n        entry: b\n";

        $this->assertTrue(ContentFile::same('trees/collections/pages.yaml', $reordered, $local));
        $this->assertFalse(ContentFile::same('trees/collections/pages.yaml', $moved, $local));
        $this->assertSame($moved, ContentFile::merge('collection-trees', 'trees/collections/pages.yaml', $moved, $local));
    }

    public function test_a_global_s_key_order_is_no_change(): void
    {
        $this->assertTrue(ContentFile::same('globals/default/settings.yaml', "phone: '123'\nemail: a@b.test\n", "email: a@b.test\nphone: '123'\n"));
        $this->assertSame("email: a@b.test\nphone: '456'\n", ContentFile::merge('globals', 'globals/default/settings.yaml', "phone: '456'\nemail: a@b.test\n", "email: a@b.test\nphone: '123'\n"));
    }
}
