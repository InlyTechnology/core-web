<?php

namespace Inly\CoreWeb\Tests\Feature\Drafts;

use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftBase;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Drafts\DraftRefused;
use Inly\CoreWeb\Drafts\DraftWriter;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class DraftWriterTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['drafts'];

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().'/core-web-drafts-'.uniqid();
        config(['statamic.revisions.enabled' => true, 'statamic.revisions.path' => $this->scratch.'/revisions']);
        Blueprint::setDirectory($this->scratch.'/blueprints');

        config(['core-web.drafts.collections' => [
            'articles' => ['blueprints' => ['article'], 'published_with' => ['lead']],
            'pages' => ['structured' => true],
        ]]);

        Collection::make('articles')->sites(['default'])->dated(true)->revisionsEnabled(true)->routes('/articles/{slug}')->save();
        Collection::make('pages')->sites(['default'])->revisionsEnabled(true)->routes('{parent_uri}/{slug}')->structureContents(['root' => true])->save();
        Collection::make('notes')->sites(['default'])->revisionsEnabled(true)->save();

        $fields = [
            ['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]],
            ['handle' => 'lead', 'field' => ['type' => 'text']],
            ['handle' => 'kind', 'field' => ['type' => 'text']],
            ['handle' => 'detail', 'field' => ['type' => 'text', 'if' => ['kind' => 'equals long']]],
        ];
        Blueprint::make('article')->setNamespace('collections.articles')->setContents(['fields' => $fields])->save();
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => $fields])->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    private function user(string $email, bool $super = true): UserContract
    {
        $user = User::make()->email($email)->data(['name' => ucfirst(strtok($email, '@'))]);
        if ($super) {
            $user->makeSuper();
        }

        $user->save();

        return $user;
    }

    private function author(?UserContract $user = null, string $message = 'Drafted by a test'): DraftAuthor
    {
        return new DraftAuthor($user ?? $this->user('ann@example.com'), 'Ann', DraftProvenance::make('test', ['request_id' => 'r-1']), $message);
    }

    private function article(array $data = ['title' => 'Live title'], bool $published = true): EntryContract
    {
        $entry = Entry::make()->collection('articles')->locale('default')->slug('live')->date('2026-10-01')->data($data)->published($published);
        $entry->save();

        return $entry;
    }

    private function writer(): DraftWriter
    {
        return app(DraftWriter::class);
    }

    private function base(EntryContract $entry): string
    {
        return app(DraftBase::class)->hash(Entry::find($entry->id()));
    }

    public function test_a_new_entry_is_an_unpublished_draft_saved_as_its_author_with_provenance(): void
    {
        $result = $this->writer()->write($this->author(), ['collection' => 'articles', 'fields' => ['title' => 'Hej på dig', 'lead' => 'Kort']]);

        $entry = Entry::find($result['entry_id']);

        $this->assertSame(DraftWriter::STATUS_DRAFT_ENTRY, $result['status']);
        $this->assertFalse($entry->published());
        $this->assertSame('hej-pa-dig', $entry->slug());
        $this->assertSame('Kort', $entry->get('lead'));
        $this->assertNotEmpty($result['preview_url']);
        $this->assertSame(['via' => 'test', 'request_id' => 'r-1'], DraftProvenance::of($entry->latestRevision()));
        $this->assertSame('Drafted by a test', $entry->latestRevision()->message());
    }

    public function test_a_published_entry_gets_a_working_copy_and_its_live_entry_is_left_alone(): void
    {
        $live = $this->article();

        $result = $this->writer()->write($this->author(), ['collection' => 'articles', 'entry_id' => $live->id(), 'fields' => ['title' => 'Draft title'], 'base_hash' => $this->base($live)]);

        $entry = Entry::find($live->id());

        $this->assertSame(DraftWriter::STATUS_WORKING_COPY, $result['status']);
        $this->assertSame(['title'], $result['changed_fields']);
        $this->assertSame('Live title', $entry->get('title'));
        $this->assertSame('Draft title', $entry->fromWorkingCopy()->get('title'));
        $this->assertSame('test', DraftProvenance::via($entry->workingCopy()));

        $read = $this->writer()->read($live->id());

        $this->assertSame(DraftWriter::STATUS_WORKING_COPY, $read['status']);
        $this->assertSame('ann@example.com', $read['held_by']['email']);
        $this->assertSame('test', $read['via']);
        $this->assertSame(['title'], $read['changed_fields']);
    }

    public function test_a_working_copy_someone_else_holds_is_never_overwritten(): void
    {
        $live = $this->article();
        $this->writer()->write($this->author($this->user('eva@example.com')), ['collection' => 'articles', 'entry_id' => $live->id(), 'fields' => ['title' => 'Eva'], 'base_hash' => $this->base($live)]);

        try {
            $this->writer()->write($this->author(), ['collection' => 'articles', 'entry_id' => $live->id(), 'fields' => ['title' => 'Ann'], 'base_hash' => $this->base($live)]);
            $this->fail('A held draft was overwritten.');
        } catch (DraftRefused $refused) {
            $this->assertSame('held', $refused->error);
            $this->assertSame('eva@example.com', $refused->details['held_by']['email']);
            $this->assertStringContainsString('Eva <eva@example.com>', $refused->getMessage());
        }
    }

    public function test_the_authors_own_working_copy_must_restate_what_it_changed(): void
    {
        $live = $this->article(['title' => 'Live title', 'lead' => 'Live lead']);
        $author = $this->author();
        $this->writer()->write($author, ['collection' => 'articles', 'entry_id' => $live->id(), 'fields' => ['lead' => 'Draft lead'], 'base_hash' => $this->base($live)]);

        try {
            $this->writer()->write($author, ['collection' => 'articles', 'entry_id' => $live->id(), 'fields' => ['title' => 'New title'], 'base_hash' => $this->base($live)]);
            $this->fail('A stale draft dropped a change.');
        } catch (DraftRefused $refused) {
            $this->assertSame('restate', $refused->error);
            $this->assertSame(['lead'], $refused->details['fields']);
        }
    }

    public function test_an_update_names_the_base_it_was_written_against(): void
    {
        $live = $this->article();

        try {
            $this->writer()->write($this->author(), ['collection' => 'articles', 'entry_id' => $live->id(), 'fields' => ['title' => 'X']]);
            $this->fail('An update without a base was written.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('base_hash', $e->errors());
        }

        try {
            $this->writer()->write($this->author(), ['collection' => 'articles', 'entry_id' => $live->id(), 'fields' => ['title' => 'X'], 'base_hash' => 'stale']);
            $this->fail('A draft was written against a stale base.');
        } catch (DraftRefused $refused) {
            $this->assertSame('base_changed', $refused->error);
            $this->assertSame($this->base($live), $refused->details['content_hash']);
        }
    }

    public function test_a_draft_needs_revisions(): void
    {
        Collection::find('articles')->revisionsEnabled(false)->save();

        try {
            $this->writer()->write($this->author(), ['collection' => 'articles', 'fields' => ['title' => 'X']]);
            $this->fail('A draft was written without revisions.');
        } catch (DraftRefused $refused) {
            $this->assertSame('revisions_off', $refused->error);
        }
    }

    public function test_a_collection_the_site_did_not_name_is_edited_in_the_cp_only(): void
    {
        $this->expectException(ValidationException::class);

        $this->writer()->write($this->author(), ['collection' => 'notes', 'fields' => ['title' => 'X']]);
    }

    public function test_an_author_who_may_not_edit_is_refused_in_the_sites_words(): void
    {
        app('translator')->addLines(['drafts.not_permitted' => ':name får inte'], 'en', 'core-web');

        try {
            $this->writer()->write($this->author($this->user('guest@example.com', super: false)), ['collection' => 'articles', 'fields' => ['title' => 'X']]);
            $this->fail('A draft was written for someone without permission.');
        } catch (DraftRefused $refused) {
            $this->assertSame([403, 'not_permitted', 'Ann får inte'], [$refused->status, $refused->error, $refused->getMessage()]);
        }
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $live = $this->article();

        $result = $this->writer()->write($this->author(), ['collection' => 'articles', 'entry_id' => $live->id(), 'dry_run' => true, 'fields' => ['title' => 'Draft'], 'base_hash' => $this->base($live)]);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(['title'], $result['changed_fields']);
        $this->assertFalse(Entry::find($live->id())->hasWorkingCopy());
    }

    public function test_a_value_its_field_conditions_hide_is_left_out_and_named(): void
    {
        $result = $this->writer()->write($this->author(), ['collection' => 'articles', 'fields' => ['title' => 'X', 'kind' => 'short', 'detail' => 'Hidden', 'unknown' => 'y']]);

        $this->assertEqualsCanonicalizing(['detail', 'unknown'], $result['ignored_fields']);
        $this->assertNull(Entry::find($result['entry_id'])->get('detail'));
    }

    public function test_a_new_entry_of_a_structured_collection_goes_under_its_parent(): void
    {
        $home = Entry::make()->collection('pages')->locale('default')->slug('home')->data(['title' => 'Home'])->published(true);
        $home->save();
        $about = Entry::make()->collection('pages')->locale('default')->slug('about')->data(['title' => 'About'])->published(true);
        $about->save();
        Collection::find('pages')->structure()->in('default')->tree([['entry' => $home->id()], ['entry' => $about->id()]])->save();

        try {
            $this->writer()->write($this->author(), ['collection' => 'pages', 'fields' => ['title' => 'Team']]);
            $this->fail('A page was drafted without a parent.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parent_id', $e->errors());
        }

        $result = $this->writer()->write($this->author(), ['collection' => 'pages', 'parent_id' => $about->id(), 'fields' => ['title' => 'Team']]);

        $tree = Collection::find('pages')->structure()->in('default')->tree();

        $this->assertSame($result['entry_id'], $tree[1]['children'][0]['entry']);
    }

    public function test_publishing_makes_the_working_copy_live_with_the_authors_provenance(): void
    {
        $live = $this->article(['title' => 'Live', 'lead' => 'Lead']);
        $author = $this->author();
        $this->writer()->write($author, ['collection' => 'articles', 'entry_id' => $live->id(), 'fields' => ['title' => 'Draft'], 'base_hash' => $this->base($live)]);

        $result = $this->writer()->publish(new DraftAuthor($author->user, 'Ann', DraftProvenance::make('test'), 'Published by a test'), $live->id());

        $entry = Entry::find($live->id());

        $this->assertSame(DraftWriter::STATUS_PUBLISHED, $result['status']);
        $this->assertSame('Draft', $entry->get('title'));
        $this->assertFalse($entry->hasWorkingCopy());
        $this->assertSame('Published by a test', $entry->latestRevision()->message());
        $this->assertSame(['via' => 'test'], DraftProvenance::of($entry->latestRevision()));
    }

    public function test_an_entry_is_never_published_without_its_published_with_fields(): void
    {
        $author = $this->author();
        $draft = $this->writer()->write($author, ['collection' => 'articles', 'fields' => ['title' => 'No lead']]);

        try {
            $this->writer()->publish($author, $draft['entry_id']);
            $this->fail('An entry was published without its lead.');
        } catch (DraftRefused $refused) {
            $this->assertSame(['image_required', ['lead']], [$refused->error, $refused->details['fields']]);
        }
    }

    public function test_publishing_a_collection_the_site_did_not_name_is_not_found(): void
    {
        $note = Entry::make()->collection('notes')->locale('default')->slug('n')->data(['title' => 'N'])->published(false);
        $note->save();

        $this->expectException(DraftRefused::class);
        $this->expectExceptionMessage('The site has no such entry to draft.');

        $this->writer()->publish($this->author(), $note->id());
    }

    public function test_the_preview_link_opens_the_draft_in_live_preview(): void
    {
        $live = $this->article();
        $result = $this->writer()->write($this->author(), ['collection' => 'articles', 'entry_id' => $live->id(), 'fields' => ['title' => 'Draft'], 'base_hash' => $this->base($live)]);

        $response = $this->get($result['preview_url']);

        $response->assertRedirect();
        $this->assertStringContainsString('/articles/live?live-preview=', $response->headers->get('Location'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->get('/core-preview/'.$live->id())->assertForbidden();
    }

    public function test_an_editors_cp_save_keeps_the_drafts_provenance(): void
    {
        $live = $this->article();
        $this->writer()->write($this->author(), ['collection' => 'articles', 'entry_id' => $live->id(), 'fields' => ['title' => 'Draft'], 'base_hash' => $this->base($live)]);

        $entry = Entry::find($live->id());
        // As the CP saves a working copy: afresh, without the draft's attributes.
        $entry->fromWorkingCopy()->set('lead', 'Edited')->makeWorkingCopy()->user($this->user('eva@example.com'))->save();

        $copy = Entry::find($live->id())->workingCopy();

        $this->assertSame('test', DraftProvenance::via($copy));
        $this->assertSame('Drafted by a test', $copy->message());
    }
}
