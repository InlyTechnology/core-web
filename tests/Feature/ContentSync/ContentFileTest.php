<?php

namespace Inly\CoreWeb\Tests\Feature\ContentSync;

use Inly\CoreWeb\ContentSync\ContentFile;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Facades\Collection;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class ContentFileTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    public function test_a_different_type_is_a_change(): void
    {
        $this->assertFalse(ContentFile::same('globals/default/settings.yaml', "count: '1'\n", "count: 1\n"));
    }

    public function test_replicator_sets_keep_their_local_key_order_in_the_site_s_set_order(): void
    {
        $local = "---\nid: home\nsets:\n  -\n    id: a\n    type: text\n    text: One\n  -\n    id: b\n    type: text\n    text: Two\n---\n";
        $remote = "---\nid: home\nsets:\n  -\n    text: Two\n    type: text\n    id: b\n  -\n    text: Uno\n    type: text\n    id: a\n---\n";

        $this->assertFalse(ContentFile::same('collections/pages/home.md', $remote, $local), 'Moving a set is a change.');
        $this->assertSame(
            "---\nid: home\nsets:\n  -\n    id: b\n    type: text\n    text: Two\n  -\n    id: a\n    type: text\n    text: Uno\n---\n",
            ContentFile::merge('entries', 'collections/pages/home.md', $remote, $local),
        );
    }

    public function test_parent_goes_from_a_structured_collection_s_entry_in_a_site_folder(): void
    {
        config(['statamic.system.multisite' => true]);
        Collection::make('pages')->sites(['default', 'other'])->structureContents(['root' => true])->save();

        $this->assertSame("---\nid: hem\ntitle: Hem\n---\n", ContentFile::canonical('entries', 'collections/pages/other/hem.md', "---\nid: hem\ntitle: Hem\nparent: hem-root\n---\n"));
    }

    public function test_a_quoted_date_round_trips_unchanged(): void
    {
        $local = "---\nid: event\ntitle: Launch\nstarts: '2024-01-01'\n---\n";

        $this->assertTrue(ContentFile::same('collections/events/event.md', $local, $local));
        $this->assertSame(
            "---\nid: event\ntitle: Party\nstarts: '2024-01-01'\n---\n",
            ContentFile::merge('entries', 'collections/events/event.md', "---\nid: event\nstarts: '2024-01-01'\ntitle: Party\n---\n", $local),
        );
    }

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
