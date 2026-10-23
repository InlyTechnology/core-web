<?php

namespace Inly\CoreWeb\Tests\Feature\Content;

use Illuminate\Support\Facades\File;
use Inly\CoreWeb\Drafts\ContentSchema;
use Inly\CoreWeb\Drafts\DraftPerson;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/** Statamic connector API v1.1: Core AI writes, reads and publishes a person's drafts. */
class CoreDraftsApiTest extends TestCase
{
    use FakesCore;
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['content', 'drafts'];

    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeCore();
        $this->scratch = sys_get_temp_dir().'/core-web-drafts-api-'.uniqid();
        config(['statamic.revisions.enabled' => true, 'statamic.revisions.path' => $this->scratch.'/revisions']);
        Blueprint::setDirectory($this->scratch.'/blueprints');
        config(['core-web.drafts.collections' => ['articles' => ['blueprints' => ['article']]]]);

        Collection::make('articles')->sites(['default'])->revisionsEnabled(true)->routes('/articles/{slug}')->save();
        Blueprint::make('article')->setNamespace('collections.articles')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text', 'validate' => ['required']]],
            ['handle' => 'lead', 'field' => ['type' => 'text']],
        ]])->save();

        User::make()->email('ann@example.com')->data(['name' => 'Ann', DraftPerson::CORE_USER_MARKER => '42'])->makeSuper()->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function actor(string $coreUserId = '42'): array
    {
        return ['actor' => ['core_user_id' => $coreUserId, 'name' => 'Ann', 'email' => 'ann@example.com'], 'summary' => 'Ny artikel', 'core_request_id' => 'req-1', 'core_approval_id' => 'appr-1'];
    }

    public function test_core_ai_drafts_reads_and_publishes_an_entry_as_the_person(): void
    {
        $id = $this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', ['collection' => 'articles', 'fields' => ['title' => 'Nyhet', 'lead' => 'Kort.'], ...$this->actor()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft_entry')
            ->json('data.entry_id');

        $entry = Entry::find($id);
        $this->assertFalse($entry->published());
        $revision = $entry->latestRevision();
        $this->assertSame(['via' => 'core-ai', 'core_user_id' => '42', 'core_request_id' => 'req-1', 'core_approval_id' => 'appr-1'], DraftProvenance::of($revision));
        $this->assertSame('Drafted by Core AI for Ann: Ny artikel', $revision->message());

        $this->callSealed('GET', "/api/core/v1/drafts/{$id}", 'website.draft')
            ->assertOk()
            ->assertJsonPath('data.status', 'draft_entry')
            ->assertJsonPath('data.fields.title', 'Nyhet');

        $this->callSealed('POST', "/api/core/v1/drafts/{$id}/publish", 'website.draft', $this->actor())->assertForbidden();

        $this->callSealed('POST', "/api/core/v1/drafts/{$id}/publish", 'website.publish', $this->actor())
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertTrue(Entry::find($id)->published());
        $this->assertStringStartsWith('Published by Core AI for Ann', Entry::find($id)->latestRevision()->message());
    }

    public function test_a_draft_is_sealed_for_drafting_not_reading(): void
    {
        $this->callSealed('POST', '/api/core/v1/drafts', 'website.read', ['collection' => 'articles', 'fields' => ['title' => 'Nyhet'], ...$this->actor()])->assertForbidden();
    }

    public function test_a_person_without_a_cms_account_is_refused(): void
    {
        $this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', ['collection' => 'articles', 'fields' => ['title' => 'Nyhet'], ...$this->actor('99')])
            ->assertForbidden()
            ->assertJsonPath('error', 'no_cms_user');
    }

    public function test_a_collection_the_site_does_not_draft_is_refused(): void
    {
        $this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', ['collection' => 'pages', 'fields' => ['title' => 'x'], ...$this->actor()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('collection');
    }

    public function test_an_unknown_draft_is_not_found(): void
    {
        $this->callSealed('GET', '/api/core/v1/drafts/nope', 'website.draft')->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', ['collection' => 'articles', 'entry_id' => 'nope', 'fields' => ['title' => 'x'], ...$this->actor()])
            ->assertNotFound()
            ->assertExactJson(['error' => 'not_found', 'message' => 'The site has no such entry to draft.']);
        $this->callSealed('POST', '/api/core/v1/drafts/nope/publish', 'website.publish', $this->actor())
            ->assertNotFound()
            ->assertExactJson(['error' => 'not_found', 'message' => 'The site has no such entry to draft.']);
    }

    public function test_the_request_is_checked_before_the_person(): void
    {
        $nobody = $this->actor('99');

        $this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', ['collection' => 'pages', 'fields' => ['title' => 'x'], ...$nobody])->assertUnprocessable();
        $this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', ['collection' => 'articles', 'entry_id' => 'nope', 'fields' => ['title' => 'x'], ...$nobody])->assertNotFound();
        $this->callSealed('POST', '/api/core/v1/drafts/nope/publish', 'website.publish', $nobody)->assertNotFound();

        config(['statamic.revisions.enabled' => false]);
        Collection::findByHandle('articles')->revisionsEnabled(false)->save();
        $this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', ['collection' => 'articles', 'fields' => ['title' => 'x'], ...$nobody])
            ->assertConflict()
            ->assertJsonPath('error', 'revisions_off');
    }

    public function test_a_draft_against_an_older_schema_is_refused(): void
    {
        $current = app(ContentSchema::class)->hash();

        $this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', ['collection' => 'articles', 'fields' => ['title' => 'Nyhet'], 'catalog_hash' => $current, 'dry_run' => true, ...$this->actor()])
            ->assertOk()
            ->assertJsonPath('data.dry_run', true);

        $this->callSealed('POST', '/api/core/v1/drafts', 'website.draft', ['collection' => 'articles', 'fields' => ['title' => 'Nyhet'], 'catalog_hash' => 'old', 'dry_run' => true, ...$this->actor()])
            ->assertConflict()
            ->assertJsonPath('error', 'catalog_changed')
            ->assertJsonPath('catalog_hash', $current);
    }
}
