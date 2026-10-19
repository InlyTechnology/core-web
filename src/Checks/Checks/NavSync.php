<?php

namespace Inly\CoreWeb\Checks\Checks;

use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\Checks\Check;
use Inly\CoreWeb\Checks\ContentWalk;
use Inly\CoreWeb\Checks\Finding;
use Inly\CoreWeb\Drafts\DraftAuthor;
use Inly\CoreWeb\Drafts\DraftRefused;
use Inly\CoreWeb\Proposals\Proposals;
use Inly\CoreWeb\Proposals\Targets\CollectionTreeTarget;
use Inly\CoreWeb\Proposals\Targets\NavTarget;
use Inly\CoreWeb\Proposals\Targets\TreeTarget;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;

/**
 * A translated site's navigations and page trees that no longer match the
 * site they translate (core-web.checks.sync.sites): the source tree mapped
 * through each entry's localization, kept as a proposal an editor applies.
 * What the translation owns stays: its labels on branches it already has,
 * branches marked to stay (`sync: false`), and, in a page tree, pages the
 * source has no counterpart of.
 */
class NavSync extends Check
{
    public function key(): string
    {
        return 'nav-sync';
    }

    public function description(): string
    {
        return 'Menus and page trees of a translated site that differ from the site it translates.';
    }

    public function detect(): iterable
    {
        foreach ($this->pairs() as [$target, $source]) {
            if (! ContentWalk::covers($target->key())) {
                continue;
            }

            [$mirrored, $missing] = $this->mirror($target, $source);
            $changes = app(Proposals::class)->changes($target, $target->current(), $mirrored);

            if ($changes === []) {
                continue;
            }

            // A mirror the target would refuse (too deep for it, say) is an editor's to sort out, not a fix that fails every night.
            try {
                $target->validate($mirrored);
                $refused = null;
            } catch (ValidationException $e) {
                $refused = collect($e->errors())->flatten()->values()->all();
            }

            yield new Finding(
                check: $this->key(),
                subject: $target->key(),
                path: '',
                message: count($changes) === 1
                    ? 'Differs from the '.Site::get($source)?->name().' version in 1 place.'
                    : 'Differs from the '.Site::get($source)?->name().' version in '.count($changes).' places.',
                site: $target->site,
                label: $target->label(),
                cpUrl: $target->cpUrl(),
                details: array_filter(['found' => $source, 'changes' => count($changes), 'left_out' => $missing, 'refused' => $refused], fn (mixed $v) => $v !== null),
                fixable: $refused === null,
            );
        }
    }

    public function fix(Finding $finding, DraftAuthor $author): array
    {
        $pair = collect($this->pairs())->first(fn (array $pair) => $pair[0]->key() === $finding->subject)
            ?? throw new DraftRefused('finding_gone', __('core-web::drafts.finding_gone'), status: 404);

        [$target, $source] = $pair;
        $base = Proposals::base($target);
        [$mirrored] = $this->mirror($target, $source);

        return app(Proposals::class)->propose($author, $target, $mirrored, $base);
    }

    /**
     * Every navigation and page tree on both a translated site and its source.
     *
     * @return list<array{0: NavTarget|CollectionTreeTarget, 1: string}>
     */
    private function pairs(): array
    {
        $navs = config('core-web.checks.sync.navs');
        $trees = config('core-web.checks.sync.trees');
        $pairs = [];

        foreach ((array) config('core-web.checks.sync.sites', []) as $to => $from) {
            $to = (string) $to;
            $from = (string) $from;

            if ($to === $from || Site::get($to) === null || Site::get($from) === null) {
                continue;
            }

            foreach (Nav::all() as $nav) {
                if ((! is_array($navs) || in_array($nav->handle(), $navs, true)) && $nav->in($to) !== null && $nav->in($from) !== null) {
                    $pairs[] = [new NavTarget($nav->handle(), $to), $from];
                }
            }

            foreach (Collection::all() as $collection) {
                if (! $collection->hasStructure() || (is_array($trees) && ! in_array($collection->handle(), $trees, true))) {
                    continue;
                }

                $sites = $collection->sites()->all();

                if (in_array($to, $sites, true) && in_array($from, $sites, true) && $collection->structure()->in($to) !== null && $collection->structure()->in($from) !== null) {
                    $pairs[] = [new CollectionTreeTarget($collection->handle(), $to), $from];
                }
            }
        }

        return $pairs;
    }

    private function sourceTree(NavTarget|CollectionTreeTarget $target, string $source): array
    {
        return $target instanceof NavTarget
            ? Nav::find($target->handle)->in($source)->tree()
            : Collection::find($target->handle)->structure()->in($source)->tree();
    }

    /**
     * The source tree in the target site's terms, and the titles of what it left out: pages with no translation, links no proposal may add.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function mirror(NavTarget|CollectionTreeTarget $target, string $source): array
    {
        $now = $target->current();
        $index = ['entry' => [], 'url' => [], 'title' => []];
        $this->index($now, null, $index);

        $used = [];
        $missing = [];
        $mirrored = $this->branches($this->sourceTree($target, $source), $target->site, $target instanceof NavTarget, $index, $used, $missing, null);

        foreach ($this->leftovers($target, $now, $used) as [$branch, $parent]) {
            $mirrored = $this->attach($mirrored, $branch, $parent);
        }

        if ($target instanceof CollectionTreeTarget) {
            $mirrored = $this->rootFirst($target, $now, $mirrored);
        }

        return [$mirrored, array_values(array_unique($missing))];
    }

    /**
     * The target's branches by what they point at, so a mirrored branch keeps the label, id and data the translation gave it.
     *
     * @param  array<mixed>  $branches
     * @param  array{entry: array<string, array<string, mixed>>, url: array<string, list<array<string, mixed>>>, title: array<string, list<array<string, mixed>>>}  $index
     */
    private function index(array $branches, ?string $parent, array &$index): void
    {
        $keep = (string) config('core-web.checks.sync.keep', 'sync');

        foreach ($branches as $branch) {
            $key = self::keyOf($branch);

            // A heading marked to stay is the translation's own, never the match for a source heading.
            if (blank($branch['entry'] ?? null) && blank($branch['url'] ?? null) && ($branch['data'][$keep] ?? null) === false) {
                $this->index((array) ($branch['children'] ?? []), $key, $index);

                continue;
            }

            match (true) {
                filled($branch['entry'] ?? null) => $index['entry'][(string) $branch['entry']] ??= $branch,
                filled($branch['url'] ?? null) => $index['url'][(string) $branch['url']][] = $branch,
                default => $index['title'][$parent ?? ''][] = $branch,
            };

            $this->index((array) ($branch['children'] ?? []), $key, $index);
        }
    }

    /**
     * @param  array<mixed>  $branches  the source's
     * @param  array{entry: array<string, array<string, mixed>>, url: array<string, list<array<string, mixed>>>, title: array<string, list<array<string, mixed>>>}  $index
     * @param  array<string, true>  $used  the target branches the mirror took, by key
     * @param  list<string>  $missing
     * @return list<array<string, mixed>>
     */
    private function branches(array $branches, string $site, bool $nav, array &$index, array &$used, array &$missing, ?string $parent): array
    {
        $out = [];

        foreach ($branches as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            $children = (array) ($branch['children'] ?? []);

            if (filled($branch['entry'] ?? null)) {
                $entry = Entry::find((string) $branch['entry']);
                $localized = $entry?->in($site);

                // A page with no translation yet leaves its place to its children, so the pages under it are not lost with it.
                if ($localized === null) {
                    $missing[] = (string) ($entry?->value('title') ?? $branch['entry']);
                    array_push($out, ...$this->branches($children, $site, $nav, $index, $used, $missing, $parent));

                    continue;
                }

                $id = (string) $localized->id();

                // A menu may list a page twice; a proposal may not, so the first place wins.
                if (isset($used['entry:'.$id])) {
                    array_push($out, ...$this->branches($children, $site, $nav, $index, $used, $missing, $parent));

                    continue;
                }

                $mirrored = $index['entry'][$id] ?? ['entry' => $id];
                unset($mirrored['children'], $mirrored['url']);
            } elseif (! $nav) {
                continue;
            } elseif (filled($branch['url'] ?? null)) {
                // A link no proposal may add (no scheme, an unknown one) is left for an editor to fix in the source.
                if (! TreeTarget::safeUrl((string) $branch['url'])) {
                    $missing[] = (string) ($branch['title'] ?? $branch['url']);
                    array_push($out, ...$this->branches($children, $site, $nav, $index, $used, $missing, $parent));

                    continue;
                }

                $mirrored = self::take($index['url'], (string) $branch['url']) ?? self::copied($branch);
                unset($mirrored['children']);
            } else {
                // A heading has nothing to match on but its place: the translation's heading at the same place keeps its label.
                $mirrored = self::take($index['title'], $parent ?? '') ?? self::copied($branch);
                unset($mirrored['children']);
            }

            $key = self::keyOf($mirrored);
            $used[$key] = true;
            $kids = $this->branches($children, $site, $nav, $index, $used, $missing, $key);

            if ($kids !== []) {
                $mirrored['children'] = $kids;
            }

            $out[] = $mirrored;
        }

        return $out;
    }

    /**
     * The target's branches the mirror leaves out but must keep, each with the key of the branch it sat under.
     *
     * @param  array<mixed>  $branches
     * @param  array<string, true>  $used
     * @return list<array{0: array<string, mixed>, 1: ?string}>
     */
    private function leftovers(NavTarget|CollectionTreeTarget $target, array $branches, array $used, ?string $parent = null): array
    {
        $keep = (string) config('core-web.checks.sync.keep', 'sync');
        $out = [];

        foreach ($branches as $branch) {
            $key = self::keyOf($branch);
            // A page tree never drops a page (a proposal may only move them); a menu keeps the branches marked to stay.
            $stays = $target instanceof CollectionTreeTarget || ($branch['data'][$keep] ?? null) === false;

            if (! isset($used[$key]) && $stays) {
                $out[] = [array_diff_key($branch, ['children' => true]), $parent];
            }

            array_push($out, ...$this->leftovers($target, (array) ($branch['children'] ?? []), $used, $key));
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $tree
     * @param  array<string, mixed>  $branch
     * @return list<array<string, mixed>>
     */
    private function attach(array $tree, array $branch, ?string $parent): array
    {
        if ($parent !== null && ($placed = $this->attachUnder($tree, $branch, $parent)) !== null) {
            return $placed;
        }

        $tree[] = $branch;

        return $tree;
    }

    /**
     * @param  list<array<string, mixed>>  $tree
     * @param  array<string, mixed>  $branch
     * @return list<array<string, mixed>>|null null when no branch of the tree has the key
     */
    private function attachUnder(array $tree, array $branch, string $parent): ?array
    {
        foreach ($tree as $i => $node) {
            if (self::keyOf($node) === $parent) {
                $tree[$i]['children'] = [...(array) ($node['children'] ?? []), $branch];

                return $tree;
            }

            if (is_array($node['children'] ?? null) && ($children = $this->attachUnder($node['children'], $branch, $parent)) !== null) {
                $tree[$i]['children'] = $children;

                return $tree;
            }
        }

        return null;
    }

    /**
     * The home page of a page tree with a root stays first and holds no pages, whatever the source's order says.
     *
     * @param  list<array<string, mixed>>  $now
     * @param  list<array<string, mixed>>  $tree
     * @return list<array<string, mixed>>
     */
    private function rootFirst(CollectionTreeTarget $target, array $now, array $tree): array
    {
        $root = $now[0]['entry'] ?? null;

        if (! Collection::find($target->handle)->structure()->expectsRoot() || blank($root)) {
            return $tree;
        }

        $under = [];
        $tree = $this->without($tree, (string) $root, $under);

        return [['entry' => (string) $root], ...$under, ...$tree];
    }

    /**
     * @param  list<array<string, mixed>>  $tree
     * @param  list<array<string, mixed>>  $children  the removed branch's children
     * @return list<array<string, mixed>>
     */
    private function without(array $tree, string $entry, array &$children): array
    {
        $out = [];

        foreach ($tree as $node) {
            if (($node['entry'] ?? null) === $entry) {
                array_push($children, ...(array) ($node['children'] ?? []));

                continue;
            }

            if (is_array($node['children'] ?? null)) {
                $node['children'] = $this->without($node['children'], $entry, $children);

                if ($node['children'] === []) {
                    unset($node['children']);
                }
            }

            $out[] = $node;
        }

        return $out;
    }

    /**
     * The first unclaimed branch under a key, claimed.
     *
     * @param  array<string, list<array<string, mixed>>>  $pool
     * @return array<string, mixed>|null
     */
    private static function take(array &$pool, string $key): ?array
    {
        return isset($pool[$key]) ? array_shift($pool[$key]) : null;
    }

    /** @param  array<string, mixed>  $branch */
    private static function copied(array $branch): array
    {
        $copy = array_intersect_key($branch, array_flip(['url', 'title', 'data']));
        // The source's own keep mark would pin the item in the translation after the source drops it.
        unset($copy['data'][(string) config('core-web.checks.sync.keep', 'sync')]);

        if (($copy['data'] ?? null) === []) {
            unset($copy['data']);
        }

        return $copy;
    }

    /** @param  array<string, mixed>  $branch */
    private static function keyOf(array $branch): string
    {
        return match (true) {
            filled($branch['entry'] ?? null) => 'entry:'.$branch['entry'],
            filled($branch['id'] ?? null) => 'branch:'.$branch['id'],
            filled($branch['url'] ?? null) => 'url:'.$branch['url'],
            default => 'title:'.($branch['title'] ?? ''),
        };
    }
}
