<?php

namespace Inly\CoreWeb\Tests\Feature\Drafts;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftProvenance;
use Inly\CoreWeb\Drafts\DraftRefused;
use Inly\CoreWeb\Proposals\Proposal;
use Inly\CoreWeb\Proposals\Proposals;
use Inly\CoreWeb\Proposals\ProposalStore;
use Inly\CoreWeb\Proposals\ProposalTargets;
use Inly\CoreWeb\Proposals\Targets\CollectionTreeTarget;
use Inly\CoreWeb\Proposals\Targets\GlobalTarget;
use Inly\CoreWeb\Proposals\Targets\NavTarget;
use Inly\CoreWeb\Proposals\Targets\TermTarget;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Blink;
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

    public function test_the_home_page_stays_first_and_holds_no_pages(): void
    {
        $target = CollectionTreeTarget::make('pages', 'default');
        $base = Proposals::base($target);

        foreach ([
            'value.0.children' => [['entry' => $this->pages['home'], 'children' => [['entry' => $this->pages['contact']]]], ['entry' => $this->pages['about'], 'children' => [['entry' => $this->pages['team']]]]],
            'value.0.entry' => [['entry' => $this->pages['contact']], ['entry' => $this->pages['home']], ['entry' => $this->pages['about'], 'children' => [['entry' => $this->pages['team']]]]],
        ] as $error => $value) {
            try {
                $this->proposals()->propose($this->author(), $target, $value, $base);
                $this->fail("Accepted a tree that breaks the home page ({$error}).");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($error, $e->errors());
            }
        }
    }

    public function test_a_menu_link_is_never_a_script_and_new_items_get_ids(): void
    {
        $target = NavTarget::make('main', 'default');

        try {
            $this->proposals()->propose($this->author(), $target, [...$target->current(), ['url' => 'javascript:alert(1)', 'title' => 'Hi']], Proposals::base($target));
            $this->fail('A script URL was proposed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('value.2.url', $e->errors());
        }

        $result = $this->proposals()->propose($this->author(), $target, [...$target->current(), ['url' => '/kontakt#karta', 'title' => 'Karta']], Proposals::base($target));
        $this->proposals()->apply($result['id'], $this->user('eva@example.com'));

        $this->assertNotEmpty(Nav::find('main')->in('default')->tree()[2]['id'] ?? null, 'An item a proposal adds has an id, as the CP gives it.');
    }

    public function test_applying_keeps_what_is_stored_outside_the_blueprint(): void
    {
        GlobalSet::find('settings')->in('default')->data(['phone' => '123', 'legacy' => 'kept'])->save();
        $target = GlobalTarget::make('settings', 'default');

        $this->assertSame(['phone' => '123'], $target->current(), 'A proposal sees only what the blueprint holds.');

        $result = $this->proposals()->propose($this->author(), $target, ['phone' => '456'], Proposals::base($target));
        $this->proposals()->apply($result['id'], $this->user('eva@example.com'));

        $this->assertEqualsCanonicalizing(['phone' => '456', 'legacy' => 'kept'], GlobalSet::find('settings')->in('default')->data()->all());
    }

    public function test_decided_proposals_leave_the_open_folder(): void
    {
        $target = NavTarget::make('main', 'default');
        $result = $this->proposals()->propose($this->author(), $target, array_reverse($target->current()), Proposals::base($target));

        $this->assertCount(1, Storage::disk('local')->files('core-web/proposals/open'));

        $this->proposals()->discard($result['id'], $this->user('eva@example.com'));

        $this->assertCount(0, Storage::disk('local')->files('core-web/proposals/open'));
        $this->assertSame(Proposal::DISCARDED, $this->proposals()->find($result['id'])->status);
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

    public function test_a_grid_change_reads_row_by_row_with_page_titles(): void
    {
        Blueprint::make('settings')->setNamespace('globals')->setContents(['fields' => [
            ['handle' => 'phone', 'field' => ['type' => 'text', 'validate' => ['required']]],
            ['handle' => 'redirects', 'field' => ['type' => 'grid', 'display' => 'Redirects', 'fields' => [
                ['handle' => 'url_old', 'field' => ['type' => 'text']],
                ['handle' => 'internal_url', 'field' => ['type' => 'entries', 'max_items' => 1]],
            ]]],
        ]])->save();
        GlobalSet::find('settings')->in('default')->data(['phone' => '123', 'redirects' => [
            ['id' => 'r-old', 'url_old' => '/old', 'internal_url' => $this->pages['about']],
            ['id' => 'r-keep', 'url_old' => '/keep', 'internal_url' => $this->pages['team']],
        ]])->save();
        $target = GlobalTarget::make('settings', 'default');

        $changes = $this->proposals()->changes($target, $target->current(), ['phone' => '123', 'redirects' => [
            ['id' => 'r-keep', 'url_old' => '/keep', 'internal_url' => $this->pages['team']],
            ['id' => 'r-new', 'url_old' => '/new', 'internal_url' => $this->pages['contact']],
        ]]);

        $this->assertEquals([
            'redirects › r-new' => ['from' => null, 'to' => "url_old: /new\ninternal_url: Contact (/contact)"],
            'redirects › r-old' => ['from' => "url_old: /old\ninternal_url: About (/about)", 'to' => null],
        ], $changes);

        $result = $this->proposals()->propose($this->author(), $target, ['phone' => '123', 'redirects' => [
            ['id' => 'r-new', 'url_old' => '/new', 'internal_url' => [$this->pages['contact']]],
        ]], Proposals::base($target));

        $this->actingAs($this->user('eva@example.com'))->get(cp_route('core-web.proposals.show', $result['id']))
            ->assertOk()
            ->assertSee('redirects › r-new')
            ->assertSee('Contact (/contact)')
            ->assertSee('Added')
            ->assertSee('Removed')
            ->assertDontSee($this->pages['contact']);
    }

    public function test_a_change_the_readable_lines_cannot_show_is_shown_raw(): void
    {
        Blueprint::make('settings')->setNamespace('globals')->setContents(['fields' => [
            ['handle' => 'phone', 'field' => ['type' => 'text']],
            ['handle' => 'intro', 'field' => ['type' => 'bard']],
            ['handle' => 'rows', 'field' => ['type' => 'grid', 'fields' => [['handle' => 'url', 'field' => ['type' => 'text']]]]],
        ]])->save();
        $target = GlobalTarget::make('settings', 'default');
        $link = fn (string $href) => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hel'], ['type' => 'text', 'text' => 'lo', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]]]];
        $rows = [['id' => 'a', 'url' => '/a'], ['id' => 'b', 'url' => '/b']];

        $changes = $this->proposals()->changes($target, ['intro' => $link('/one'), 'rows' => $rows], ['intro' => $link('https://evil.example'), 'rows' => array_reverse($rows)]);

        $this->assertSame(['intro', 'rows › order'], array_keys($changes));
        $this->assertStringContainsString('https://evil.example', $changes['intro']['to']);
        $this->assertSame(['from' => 'a, b', 'to' => 'b, a'], $changes['rows › order']);
        $this->assertSame('Hello', $target->outline(['intro' => $link('/one')])['intro']);
    }

    public function test_rows_without_their_own_ids_are_compared_by_position(): void
    {
        $target = GlobalTarget::make('settings', 'default');

        $changes = $this->proposals()->changes($target, ['rows' => [['id' => 'x', 'url' => '/a'], ['id' => 'x', 'url' => '/b']]], ['rows' => [['id' => 'x', 'url' => '/a'], ['id' => 'x', 'url' => '/c']]]);

        $this->assertSame(['rows › #2' => ['from' => 'url: /b', 'to' => 'url: /c']], $changes);
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

    public function test_an_applied_proposal_is_undone_to_the_value_it_replaced(): void
    {
        $target = NavTarget::make('main', 'default');
        $before = array_map(fn (array $item) => array_diff_key($item, ['id' => true]), $target->current());
        $result = $this->proposals()->propose($this->author(), $target, [['entry' => $this->pages['team']]], Proposals::base($target));
        $editor = $this->user('eva@example.com');
        $this->proposals()->apply($result['id'], $editor);

        $this->assertTrue($this->proposals()->describe($this->proposals()->find($result['id']))['undoable']);

        $undone = $this->proposals()->undo($result['id'], $editor);

        $this->assertSame(Proposal::UNDONE, $undone->status);
        $this->assertSame((string) $editor->id(), $this->proposals()->find($result['id'])->undoneBy);
        $this->assertSame($before, array_map(fn (array $item) => array_diff_key($item, ['id' => true]), NavTarget::make('main', 'default')->current()));

        try {
            $this->proposals()->undo($result['id'], $editor);
            $this->fail('A proposal was undone twice.');
        } catch (DraftRefused $refused) {
            $this->assertSame('not_undoable', $refused->error);
        }
    }

    public function test_an_undo_never_overwrites_a_later_change(): void
    {
        $target = GlobalTarget::make('settings', 'default');
        $result = $this->proposals()->propose($this->author(), $target, ['phone' => '456'], Proposals::base($target));
        $editor = $this->user('eva@example.com');
        $this->proposals()->apply($result['id'], $editor);
        GlobalSet::find('settings')->in('default')->set('phone', '789')->save();

        $this->assertFalse($this->proposals()->undoable($this->proposals()->find($result['id'])));

        try {
            $this->proposals()->undo($result['id'], $editor);
            $this->fail('An undo overwrote a later change.');
        } catch (DraftRefused $refused) {
            $this->assertSame('changed_since_applied', $refused->error);
        }

        $this->assertSame('789', GlobalSet::find('settings')->in('default')->get('phone'));
    }

    public function test_an_undo_puts_a_page_tree_back(): void
    {
        $target = CollectionTreeTarget::make('pages', 'default');
        $result = $this->proposals()->propose($this->author(), $target, [
            ['entry' => $this->pages['home']],
            ['entry' => $this->pages['about']],
            ['entry' => $this->pages['contact'], 'children' => [['entry' => $this->pages['team']]]],
        ], Proposals::base($target));
        $editor = $this->user('eva@example.com');
        $this->proposals()->apply($result['id'], $editor);

        $this->proposals()->undo($result['id'], $editor);

        $this->assertSame('/about/team', Entry::find($this->pages['team'])->url());
    }

    public function test_an_undo_restores_what_the_cp_saved_even_where_a_proposal_could_not(): void
    {
        Nav::find('main')->in('default')->tree([['entry' => $this->pages['about']], ['url' => 'kontakt', 'title' => 'Typed in the CP']])->save();
        $target = NavTarget::make('main', 'default');
        $result = $this->proposals()->propose($this->author(), $target, [['entry' => $this->pages['about']]], Proposals::base($target));
        $editor = $this->user('eva@example.com');
        $this->proposals()->apply($result['id'], $editor);

        $this->proposals()->undo($result['id'], $editor);

        $this->assertSame('kontakt', Nav::find('main')->in('default')->tree()[1]['url']);
    }

    public function test_an_undo_brings_back_values_the_blueprint_hides(): void
    {
        Blueprint::make('settings')->setNamespace('globals')->setContents(['fields' => [
            ['handle' => 'phone', 'field' => ['type' => 'text']],
            ['handle' => 'show', 'field' => ['type' => 'toggle']],
            ['handle' => 'note', 'field' => ['type' => 'text', 'if' => ['show' => 'equals true']]],
        ]])->save();
        Blink::flush();
        GlobalSet::find('settings')->in('default')->data(['phone' => '1', 'show' => false, 'note' => 'kept hidden'])->save();
        $target = GlobalTarget::make('settings', 'default');
        $result = $this->proposals()->propose($this->author(), $target, ['phone' => '2', 'show' => false], Proposals::base($target));
        $editor = $this->user('eva@example.com');
        $this->proposals()->apply($result['id'], $editor);

        $this->proposals()->undo($result['id'], $editor);

        $this->assertSame('kept hidden', GlobalSet::find('settings')->in('default')->get('note'));
        $this->assertSame('1', GlobalSet::find('settings')->in('default')->get('phone'));
    }

    public function test_only_the_last_proposal_applied_to_a_target_is_listed(): void
    {
        $target = GlobalTarget::make('settings', 'default');
        $editor = $this->user('eva@example.com');
        $first = $this->proposals()->propose($this->author(), $target, ['phone' => '456'], Proposals::base($target));
        $this->proposals()->apply($first['id'], $editor);
        $second = $this->proposals()->propose($this->author(), $target, ['phone' => '789'], Proposals::base($target));
        $this->proposals()->apply($second['id'], $editor);

        $this->assertSame([$second['id']], array_map(fn (Proposal $proposal) => $proposal->id, $this->proposals()->undoableApplied()));

        $this->proposals()->undo($second['id'], $editor);
        $this->assertSame([], $this->proposals()->undoableApplied());
        $this->assertSame('456', GlobalSet::find('settings')->in('default')->get('phone'));
    }

    public function test_only_someone_who_may_edit_the_target_undoes(): void
    {
        $target = NavTarget::make('main', 'default');
        $result = $this->proposals()->propose($this->author(), $target, [['entry' => $this->pages['team']]], Proposals::base($target));
        $this->proposals()->apply($result['id'], $this->user('eva@example.com'));

        try {
            $this->proposals()->undo($result['id'], $this->user('viewer@example.com', super: false));
            $this->fail('Someone who may not edit the nav undid a proposal.');
        } catch (DraftRefused $refused) {
            $this->assertSame('not_permitted', $refused->error);
        }

        $this->assertSame($this->pages['team'], Nav::find('main')->in('default')->tree()[0]['entry']);
    }

    public function test_a_proposal_applied_before_undo_existed_is_not_undoable(): void
    {
        $target = NavTarget::make('main', 'default');
        $result = $this->proposals()->propose($this->author(), $target, [['entry' => $this->pages['team']]], Proposals::base($target));
        $proposal = $this->proposals()->find($result['id']);
        app(ProposalStore::class)->save($proposal->decide(Proposal::APPLIED, 'someone'));

        $this->assertFalse($this->proposals()->undoable($this->proposals()->find($result['id'])));
    }

    public function test_an_editor_undoes_an_applied_proposal_in_the_cp(): void
    {
        $target = NavTarget::make('main', 'default');
        $result = $this->proposals()->propose($this->author(), $target, [['entry' => $this->pages['team']]], Proposals::base($target));
        $editor = $this->user('eva@example.com');
        $this->actingAs($editor)->post(cp_route('core-web.proposals.apply', $result['id']));

        $this->actingAs($editor)->get(cp_route('core-web.proposals.index'))->assertOk()->assertSee('Recently applied')->assertSee(cp_route('core-web.proposals.undo', $result['id']));
        $this->actingAs($editor)->get(cp_route('core-web.proposals.show', $result['id']))->assertOk()->assertSee('Undo')->assertSee('Before');

        $this->actingAs($editor)->post(cp_route('core-web.proposals.undo', $result['id']))->assertRedirect(cp_route('core-web.proposals.index'));

        $this->assertSame($this->pages['about'], Nav::find('main')->in('default')->tree()[0]['entry']);
        $this->actingAs($editor)->get(cp_route('core-web.proposals.index'))->assertDontSee('Recently applied');
    }
}
