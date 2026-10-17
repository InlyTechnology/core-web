<?php

namespace Inly\CoreWeb\Tests\Feature\Content;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Inly\CoreWeb\Checks\ContentChecks;
use Inly\CoreWeb\Drafts\DraftPerson;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Testing\FakesCore;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

/** Protocol v1.2: Core reads a site's checks and proposal targets, and asks for fixes and proposals for a person. */
class ContentProtocolTest extends TestCase
{
    use FakesCore;
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['content', 'drafts', 'checks'];

    private string $scratch;

    private EntryContract $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeCore();
        Storage::fake('local');
        $this->scratch = sys_get_temp_dir().'/core-web-protocol-'.uniqid();
        config(['statamic.revisions.enabled' => true, 'statamic.revisions.path' => $this->scratch.'/revisions']);
        Blueprint::setDirectory($this->scratch.'/blueprints');
        config(['core-web.drafts.collections' => ['pages' => []]]);

        Collection::make('pages')->sites(['default'])->revisionsEnabled(true)->routes('/{slug}')->save();
        Blueprint::make('page')->setNamespace('collections.pages')->setContents(['fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
            ['handle' => 'cta_link', 'field' => ['type' => 'link']],
            ['handle' => 'seo_description', 'field' => ['type' => 'text']],
        ]])->save();

        $this->contact = $this->page('kontakt', ['title' => 'Kontakt', 'seo_description' => 'Ring oss.']);

        $nav = Nav::make('main')->title('Main')->collections(['pages']);
        $nav->save();
        $nav->makeTree('default', [['url' => '/kontakt', 'title' => 'Kontakt']])->save();

        User::make()->email('ann@example.com')->data(['name' => 'Ann', DraftPerson::CORE_USER_MARKER => '42'])->makeSuper()->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->scratch);

        parent::tearDown();
    }

    /** @param  array<string, mixed>  $data */
    private function page(string $slug, array $data): EntryContract
    {
        $entry = Entry::make()->collection('pages')->locale('default')->slug($slug)->data($data)->published(true);
        $entry->save();

        return $entry;
    }

    /** @return array<string, mixed> */
    private function actor(string $coreUserId = '42'): array
    {
        return ['actor' => ['core_user_id' => $coreUserId, 'name' => 'Ann', 'email' => 'ann@example.com'], 'summary' => 'Länkar till sidan', 'core_request_id' => 'req-1'];
    }

    public function test_core_reads_the_checks_and_their_findings(): void
    {
        $page = $this->page('om-oss', ['title' => 'Om oss', 'seo_description' => 'Vi.', 'cta_link' => '/kontakt']);
        $this->artisan('content:check')->assertSuccessful();

        $this->callSealed('GET', '/api/core/v1/checks', 'website.read')
            ->assertOk()
            ->assertJsonFragment(['key' => 'own-url-links', 'count' => 2, 'fixable' => 2]);

        $this->callSealed('GET', '/api/core/v1/checks/own-url-links/findings?subject=entry:'.$page->id(), 'website.read')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.path', 'cta_link');

        $this->callSealed('GET', '/api/core/v1/checks/nope/findings', 'website.read')->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->callSealed('POST', '/api/core/v1/checks/nope/fix', 'website.draft', ['finding_id' => 'x', ...$this->actor()])->assertNotFound()->assertJsonPath('error', 'not_found');
    }

    public function test_a_fix_core_asks_for_lands_as_a_draft_marked_as_core_ai(): void
    {
        $page = $this->page('om-oss', ['title' => 'Om oss', 'seo_description' => 'Vi.', 'cta_link' => '/kontakt']);
        $finding = collect(app(ContentChecks::class)->run(app(ContentChecks::class)->get('own-url-links'))['findings'])->firstWhere('path', 'cta_link');

        $this->callSealed('POST', '/api/core/v1/checks/own-url-links/fix', 'website.draft', ['finding_id' => $finding['id'], ...$this->actor()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'working_copy');

        $entry = Entry::find($page->id());
        $this->assertSame('/kontakt', $entry->get('cta_link'));
        $this->assertSame('entry::'.$this->contact->id(), $entry->fromWorkingCopy()->get('cta_link'));
        $this->assertSame('core-ai', DraftProvenance::via($entry->workingCopy()));
    }

    public function test_a_fix_is_sealed_for_drafting_not_reading(): void
    {
        $this->callSealed('POST', '/api/core/v1/checks/own-url-links/fix', 'website.read', ['finding_id' => 'x', ...$this->actor()])->assertForbidden();
    }

    public function test_a_person_without_a_cms_account_is_refused(): void
    {
        $this->callSealed('POST', '/api/core/v1/proposals', 'website.draft', ['target' => 'nav:main:default', 'value' => [], 'base' => 'x', ...$this->actor('99')])
            ->assertForbidden()
            ->assertJsonPath('error', 'no_cms_user');
    }

    public function test_core_reads_a_target_and_proposes_a_change_an_editor_applies(): void
    {
        $target = $this->callSealed('GET', '/api/core/v1/proposal-target?key=nav:main:default', 'website.read')
            ->assertOk()
            ->assertJsonPath('data.key', 'nav:main:default')
            ->assertJsonPath('data.open_proposal', null)
            ->json('data');

        $value = [['entry' => $this->contact->id(), 'title' => 'Kontakt']];

        $id = $this->callSealed('POST', '/api/core/v1/proposals', 'website.draft', ['target' => 'nav:main:default', 'value' => $value, 'base' => $target['base'], ...$this->actor()])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame('/kontakt', Nav::find('main')->in('default')->tree()[0]['url'], 'Nothing changes until an editor applies it.');

        $this->callSealed('GET', "/api/core/v1/proposals/{$id}", 'website.draft')
            ->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.value.0.entry', $this->contact->id());

        $this->callSealed('GET', '/api/core/v1/proposal-target?key=nav:main:default', 'website.read')
            ->assertJsonPath('data.open_proposal.id', $id);
    }

    public function test_an_unknown_target_or_proposal_is_not_found(): void
    {
        $this->callSealed('GET', '/api/core/v1/proposal-target?key=nav:nope:default', 'website.read')->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->callSealed('GET', '/api/core/v1/proposals/00000000-0000-0000-0000-000000000000', 'website.draft')->assertNotFound()->assertJsonPath('error', 'not_found');
    }
}
