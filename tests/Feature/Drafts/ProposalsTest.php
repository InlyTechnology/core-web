<?php

namespace Inly\CoreWeb\Tests\Feature\Drafts;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Drafts\DraftRefused;
use Inly\CoreWeb\Proposals\Proposal;
use Inly\CoreWeb\Proposals\Proposals;
use Inly\CoreWeb\Proposals\ProposalTargets;
use Inly\CoreWeb\Proposals\Targets\CollectionTreeTarget;
use Inly\CoreWeb\Proposals\Targets\GlobalTarget;
use Inly\CoreWeb\Proposals\Targets\NavTarget;
use Inly\CoreWeb\Proposals\Targets\TermTarget;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class ProposalsTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['drafts'];

    /** @var array<string, string> */
    private array $pages = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Blueprint::setDirectory(sys_get_temp_dir().'/core-web-proposals-'.uniqid());

        Collection::make('pages')->sites(['default'])->routes('{parent_uri}/{slug}')->structureContents(['root' => true])->save();

        foreach (['home', 'about', 'team', 'contact'] as $slug) {
            $entry = Entry::make()->collection('pages')->locale('default')->slug($slug)->data(['title' => ucfirst($slug)])->published(true);
            $entry->save();
            $this->pages[$slug] = $entry->id();
        }

        Collection::find('pages')->structure()->in('default')->tree([
            ['entry' => $this->pages['home']],
            ['entry' => $this->pages['about'], 'children' => [['entry' => $this->pages['team']]]],
            ['entry' => $this->pages['contact']],
        ])->save();

        $nav = Nav::make('main')->title('Main')->collections(['pages'])->maxDepth(2);
        $nav->save();
        $nav->makeTree('default', [['entry' => $this->pages['about']], ['url' => 'https://example.com', 'title' => 'Shop']])->save();

        $set = GlobalSet::make('settings')->title('Settings');
        $set->save();
        $set->makeLocalization('default')->data(['phone' => '123'])->save();
        Blueprint::make('settings')->setNamespace('globals')->setContents(['fields' => [
            ['handle' => 'phone', 'field' => ['type' => 'text', 'validate' => ['required']]],
            ['handle' => 'email', 'field' => ['type' => 'text', 'validate' => ['nullable', 'email']]],
        ]])->save();
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

    private function author(?UserContract $user = null): DraftAuthor
    {
        return new DraftAuthor($user ?? $this->user('ann@example.com'), 'Ann', DraftProvenance::make('test'), 'Reordered by a test');
    }

    private function proposals(): Proposals
    {
        return app(Proposals::class);
    }

    public function test_a_nav_proposal_is_staged_and_applied_by_an_editor(): void
    {
        $target = NavTarget::make('main', 'default');
        $value = [['entry' => $this->pages['contact']], ...$target->current()];

        $result = $this->proposals()->propose($this->author(), $target, $value, Proposals::base($target));

        $this->assertSame('nav:main:default', $result['target']);
        $this->assertSame(['1' => ['from' => 'About', 'to' => 'Contact'], '2' => ['from' => 'Shop (https://example.com)', 'to' => 'About'], '3' => ['from' => null, 'to' => 'Shop (https://example.com)']], $result['changed']);
        $this->assertCount(2, Nav::find('main')->in('default')->tree(), 'Proposing leaves the nav as it is.');

        $applied = $this->proposals()->apply($result['id'], $this->user('eva@example.com'));

        $this->assertSame(Proposal::APPLIED, $applied->status);
        $this->assertSame($this->pages['contact'], Nav::find('main')->in('default')->tree()[0]['entry']);
    }

    public function test_one_open_proposal_per_target(): void
    {
        $target = NavTarget::make('main', 'default');
        $base = Proposals::base($target);
        $author = $this->author();

        $first = $this->proposals()->propose($author, $target, [['entry' => $this->pages['team']]], $base);
        $second = $this->proposals()->propose($author, $target, [['entry' => $this->pages['contact']]], $base);

        $this->assertSame(Proposal::REPLACED, $this->proposals()->find($first['id'])->status);
        $this->assertCount(1, $this->proposals()->open());

        try {
            $this->proposals()->propose($this->author($this->user('eva@example.com')), $target, [['entry' => $this->pages['home']]], $base);
            $this->fail('Someone else\'s proposal was replaced.');
        } catch (DraftRefused $refused) {
            $this->assertSame('held', $refused->error);
            $this->assertSame('ann@example.com', $refused->details['held_by']['email']);
        }

        $this->assertTrue($this->proposals()->find($second['id'])->open());
    }

    public function test_a_proposal_is_never_applied_over_a_later_change(): void
    {
        $target = NavTarget::make('main', 'default');
        $result = $this->proposals()->propose($this->author(), $target, [['entry' => $this->pages['team']]], Proposals::base($target));

        Nav::find('main')->in('default')->tree([['entry' => $this->pages['home']]])->save();

        $this->assertTrue($this->proposals()->describe($this->proposals()->find($result['id']))['stale']);

        try {
            $this->proposals()->apply($result['id'], $this->user('eva@example.com'));
            $this->fail('A stale proposal was applied.');
        } catch (DraftRefused $refused) {
            $this->assertSame('base_changed', $refused->error);
        }

        $this->assertSame($this->pages['home'], Nav::find('main')->in('default')->tree()[0]['entry']);
    }

    public function test_a_proposal_names_the_base_it_was_made_from(): void
    {
        $target = NavTarget::make('main', 'default');

        $this->expectException(DraftRefused::class);

        $this->proposals()->propose($this->author(), $target, [['entry' => $this->pages['team']]], 'stale');
    }

    public function test_a_tree_is_checked_as_the_cp_would(): void
    {
        $target = NavTarget::make('main', 'default');

        try {
            $this->proposals()->propose($this->author(), $target, [
                ['entry' => 'nope'],
                ['entry' => $this->pages['team'], 'children' => [['entry' => $this->pages['team'], 'children' => [['title' => 'Too deep']]]]],
            ], Proposals::base($target));
            $this->fail('An invalid tree was proposed.');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['value.0.entry', 'value.1.children.0.entry', 'value.1.children.0.children.0'], array_keys($e->errors()));
        }
    }

    public function test_a_page_tree_proposal_moves_pages_and_never_drops_them(): void
    {
        $target = CollectionTreeTarget::make('pages', 'default');

        try {
            $this->proposals()->propose($this->author(), $target, [['entry' => $this->pages['home']], ['entry' => $this->pages['about']]], Proposals::base($target));
            $this->fail('A page was dropped from its tree.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('value', $e->errors());
        }

        $result = $this->proposals()->propose($this->author(), $target, [
            ['entry' => $this->pages['home']],
            ['entry' => $this->pages['about']],
            ['entry' => $this->pages['contact'], 'children' => [['entry' => $this->pages['team']]]],
        ], Proposals::base($target));

        $this->proposals()->apply($result['id'], $this->user('eva@example.com'));

        $this->assertSame('/contact/team', Entry::find($this->pages['team'])->url());
    }

    public function test_a_global_proposal_is_validated_against_its_blueprint(): void
    {
        $target = GlobalTarget::make('settings', 'default');

        try {
            $this->proposals()->propose($this->author(), $target, ['phone' => '', 'email' => 'nope'], Proposals::base($target));
            $this->fail('Invalid values were proposed.');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['value.phone', 'value.email'], array_keys($e->errors()));
        }

        $result = $this->proposals()->propose($this->author(), $target, ['phone' => '456', 'email' => 'hej@example.com', 'unknown' => 'x'], Proposals::base($target));
        $this->proposals()->apply($result['id'], $this->user('eva@example.com'));

        $this->assertSame(['phone' => '456', 'email' => 'hej@example.com'], GlobalSet::find('settings')->in('default')->data()->all());
    }

    public function test_a_proposal_that_changes_nothing_is_refused(): void
    {
        $target = GlobalTarget::make('settings', 'default');

        try {
            $this->proposals()->propose($this->author(), $target, ['phone' => '123'], Proposals::base($target));
            $this->fail('An empty proposal was staged.');
        } catch (DraftRefused $refused) {
            $this->assertSame('unchanged', $refused->error);
        }
    }

    public function test_only_someone_who_may_edit_the_target_proposes_or_applies(): void
    {
        $target = NavTarget::make('main', 'default');

        try {
            $this->proposals()->propose($this->author($this->user('guest@example.com', super: false)), $target, [['entry' => $this->pages['team']]], Proposals::base($target));
            $this->fail('A proposal was staged by someone who may not edit the nav.');
        } catch (DraftRefused $refused) {
            $this->assertSame('not_permitted', $refused->error);
        }

        $result = $this->proposals()->propose($this->author(), $target, [['entry' => $this->pages['team']]], Proposals::base($target));

        $this->expectException(DraftRefused::class);

        $this->proposals()->apply($result['id'], $this->user('viewer@example.com', super: false));
    }

    public function test_a_term_proposal_keeps_the_terms_bookkeeping(): void
    {
        Taxonomy::make('tags')->sites(['default'])->save();
        Term::make('news')->taxonomy('tags')->dataForLocale('default', ['title' => 'News'])->save();
        $target = TermTarget::make('tags', 'news', 'default');

        $result = $this->proposals()->propose($this->author(), $target, ['title' => 'Nyheter'], Proposals::base($target));
        $this->proposals()->apply($result['id'], $editor = $this->user('eva@example.com'));

        $term = Term::find('tags::news')->in('default');

        $this->assertSame('Nyheter', $term->get('title'));
        $this->assertSame($editor->id(), $term->get('updated_by'));
    }

    public function test_targets_resolve_from_their_keys(): void
    {
        $this->assertSame('nav:main:default', ProposalTargets::resolve('nav:main:default')->key());
        $this->assertSame('tree:pages:default', ProposalTargets::resolve('tree:pages:default')->key());
        $this->assertSame('global:settings:default', ProposalTargets::resolve('global:settings:default')->key());

        $this->expectException(\InvalidArgumentException::class);

        ProposalTargets::resolve('nav:missing:default');
    }

    public function test_an_editor_reviews_and_applies_a_proposal_in_the_cp(): void
    {
        $target = NavTarget::make('main', 'default');
        $result = $this->proposals()->propose($this->author(), $target, [['entry' => $this->pages['team']]], Proposals::base($target));
        $editor = $this->user('eva@example.com');

        $this->actingAs($editor)->get(cp_route('core-web.proposals.index'))->assertOk()->assertSee('Main');
        $this->actingAs($editor)->get(cp_route('core-web.proposals.show', $result['id']))->assertOk()->assertSee('Team');

        $this->actingAs($editor)->post(cp_route('core-web.proposals.apply', $result['id']))->assertRedirect(cp_route('core-web.proposals.index'));

        $this->assertSame($this->pages['team'], Nav::find('main')->in('default')->tree()[0]['entry']);
    }

    public function test_an_editor_discards_a_proposal_in_the_cp(): void
    {
        $target = NavTarget::make('main', 'default');
        $result = $this->proposals()->propose($this->author(), $target, [['entry' => $this->pages['team']]], Proposals::base($target));

        $this->actingAs($this->user('eva@example.com'))->post(cp_route('core-web.proposals.discard', $result['id']))->assertRedirect();

        $this->assertSame(Proposal::DISCARDED, $this->proposals()->find($result['id'])->status);
        $this->assertSame($this->pages['about'], Nav::find('main')->in('default')->tree()[0]['entry']);
    }
}
