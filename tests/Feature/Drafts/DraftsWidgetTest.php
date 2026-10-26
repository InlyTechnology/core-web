<?php

namespace Inly\CoreWeb\Tests\Feature\Drafts;

use Illuminate\Support\Facades\File;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftBase;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Drafts\DraftWriter;
use Inly\CoreWeb\Drafts\OpenDrafts;
use Inly\CoreWeb\Tests\TestCase;
use Inly\CoreWeb\Widgets\DraftsWidget;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Statamic\Widgets\Loader;

/** The `core_drafts` dashboard widget: drafts a source wrote that nobody has published yet. */
class DraftsWidgetTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['drafts'];

    private string $scratch;

    private UserContract $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().'/core-web-drafts-widget-'.uniqid();
        config(['statamic.revisions.enabled' => true, 'statamic.revisions.path' => $this->scratch.'/revisions']);
        config(['core-web.drafts.collections' => ['articles' => []]]);

        Collection::make('articles')->sites(['default'])->revisionsEnabled(true)->routes('/articles/{slug}')->save();

        $this->editor = User::make()->email('ann@example.com')->data(['name' => 'Ann'])->makeSuper();
        $this->editor->save();
        $this->actingAs($this->editor);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    private function draft(string $via, array $request, string $message): array
    {
        return app(DraftWriter::class)->write(new DraftAuthor($this->editor, 'Ann', DraftProvenance::make($via), $message), ['collection' => 'articles', ...$request]);
    }

    /** @param  array<string, mixed>  $config */
    private function render(array $config = []): string
    {
        return (string) app(Loader::class)->load('core_drafts', ['type' => 'core_drafts', ...$config])->html();
    }

    public function test_it_is_registered_with_the_drafts_feature(): void
    {
        $this->assertSame(DraftsWidget::class, app('statamic.widgets')->get('core_drafts'));
    }

    public function test_it_lists_new_entries_and_working_copies_a_source_drafted(): void
    {
        $live = Entry::make()->collection('articles')->locale('default')->slug('live')->data(['title' => 'Live title'])->published(true);
        $live->save();

        $this->draft('core-ai', ['fields' => ['title' => 'A new article']], 'Drafted by Core AI for Eva');
        $this->draft('content-push', ['entry_id' => $live->id(), 'fields' => ['title' => 'Pushed title'], 'base_hash' => app(DraftBase::class)->hash($live)], 'Pushed by Ann from laptop');

        $html = $this->render();

        $this->assertStringContainsString('A new article', $html);
        $this->assertStringContainsString('New page', $html);
        $this->assertStringContainsString('Core AI · Drafted by Core AI for Eva', $html);
        $this->assertStringContainsString('Pushed title', $html);
        $this->assertStringContainsString('Content push · Pushed by Ann from laptop', $html);
        $this->assertStringContainsString('href="'.$live->editUrl().'"', $html);
        $this->assertStringContainsString('text="2"', $html);
    }

    public function test_it_narrows_to_the_configured_sources(): void
    {
        $this->draft('core-ai', ['fields' => ['title' => 'From Core AI']], 'AI');
        $this->draft('content-push', ['fields' => ['title' => 'From a push']], 'Push');

        $html = $this->render(['via' => ['core-ai']]);

        $this->assertStringContainsString('From Core AI', $html);
        $this->assertStringNotContainsString('From a push', $html);
    }

    public function test_entries_nobody_drafted_and_published_drafts_are_left_out(): void
    {
        Entry::make()->collection('articles')->locale('default')->slug('hand')->data(['title' => 'Written by hand'])->published(false)->save();
        $result = $this->draft('core-ai', ['fields' => ['title' => 'Went live']], 'AI');
        Entry::find($result['entry_id'])->published(true)->save();

        $drafts = app(OpenDrafts::class)->for($this->editor);

        $this->assertSame([], $drafts);
        $this->assertStringContainsString('No drafts waiting', $this->render());
    }

    public function test_it_shows_only_drafts_the_editor_may_view(): void
    {
        $this->draft('core-ai', ['fields' => ['title' => 'Secret']], 'AI');

        $reader = User::make()->email('bo@example.com');
        $reader->save();

        $this->assertSame([], app(OpenDrafts::class)->for($reader));
        $this->assertSame([], app(OpenDrafts::class)->for(null));
    }
}
