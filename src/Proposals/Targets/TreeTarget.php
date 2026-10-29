<?php

namespace Inly\CoreWeb\Proposals\Targets;

use Illuminate\Validation\ValidationException;
use Inly\CoreWeb\Proposals\Moves;
use Inly\CoreWeb\Proposals\ProposalTarget;
use Statamic\Contracts\Structures\Tree;
use Statamic\Facades\Entry;

/**
 * A tree of branches (a navigation, a collection's page tree): a list of
 * branches, each an `entry` id or a `url`/`title`, with optional `children`.
 * A proposal restates the whole tree.
 */
abstract class TreeTarget implements ProposalTarget
{
    /** @var list<string> the entry ids the tree holds now */
    private array $held = [];

    /** @var list<string> entry ids the tree may hold before they exist, as a push that also creates them checks it */
    private array $expected = [];

    abstract protected function tree(): Tree;

    /** Whether a branch may be a URL rather than an entry (a navigation's may, a page tree's may not). */
    abstract protected function allowsUrls(): bool;

    /** @return list<string> the collections a branch's entry may come from */
    abstract protected function collections(): array;

    /** @return list<string>|null the handles a branch's `data` may hold; null when a branch holds none */
    protected function dataFields(): ?array
    {
        return null;
    }

    public function current(): array
    {
        return $this->tree()->tree();
    }

    /**
     * This target, checking a tree that may hold the given entries before they are saved.
     *
     * @param  list<string>  $ids
     */
    public function expecting(array $ids): static
    {
        $copy = clone $this;
        $copy->expected = array_values($ids);

        return $copy;
    }

    public function validate(array $value): array
    {
        $errors = [];
        $seen = [];
        $this->held = $this->entryIdsIn($this->current());
        $tree = $this->branches($value, 'value', $errors, $seen, 1);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $tree;
    }

    /**
     * @param  array<mixed>  $branches
     * @param  array<string, list<string>>  $errors
     * @param  array<string, true>  $seen
     * @return list<array<string, mixed>>
     */
    private function branches(array $branches, string $path, array &$errors, array &$seen, int $depth): array
    {
        if (! array_is_list($branches)) {
            $errors[$path][] = 'A tree is a list of branches.';

            return [];
        }

        $max = $this->tree()->structure()->maxDepth();
        $out = [];

        foreach ($branches as $index => $branch) {
            $at = "{$path}.{$index}";

            if (! is_array($branch)) {
                $errors[$at][] = 'A branch is an object with an `entry` or a `url`.';

                continue;
            }

            if ($max !== null && $depth > $max) {
                $errors[$at][] = "The tree is at most {$max} levels deep.";
            }

            $clean = array_intersect_key($branch, array_flip($this->allowsUrls() ? ['id', 'entry', 'url', 'title'] : ['id', 'entry']));
            $data = $this->dataFields() === null ? [] : array_intersect_key(is_array($branch['data'] ?? null) ? $branch['data'] : [], array_flip($this->dataFields()));

            if ($data !== []) {
                $clean['data'] = $data;
            }

            // A menu link a proposal adds goes to a page, a mail or a phone, never a script.
            if (filled($branch['url'] ?? null) && ! self::safeUrl((string) $branch['url'])) {
                $errors["{$at}.url"][] = 'A branch URL is a web address, a path, an anchor, mailto: or tel:.';
            }

            if (filled($branch['entry'] ?? null)) {
                $entry = Entry::find((string) $branch['entry']);

                // A branch the tree holds already stays, even when its entry is gone: that is the editor's to clear.
                $kept = ! $entry && (in_array((string) $branch['entry'], $this->held, true) || in_array((string) $branch['entry'], $this->expected, true));

                if (! $kept && (! $entry || ! in_array($entry->collectionHandle(), $this->collections(), true) || $entry->locale() !== $this->tree()->locale())) {
                    $errors["{$at}.entry"][] = "No entry `{$branch['entry']}` this tree can hold.";
                } elseif (isset($seen[(string) $branch['entry']])) {
                    $errors["{$at}.entry"][] = "The entry `{$branch['entry']}` is in the tree twice.";
                }

                $seen[(string) $branch['entry']] = true;
                $clean['entry'] = (string) $branch['entry'];
            } elseif (! $this->allowsUrls()) {
                $errors["{$at}.entry"][] = 'Every branch of this tree is an entry.';
            } elseif (blank($branch['url'] ?? null) && blank($branch['title'] ?? null)) {
                $errors[$at][] = 'A branch has an `entry`, a `url` or a `title`.';
            }

            if (isset($branch['children'])) {
                $children = $this->branches(is_array($branch['children']) ? $branch['children'] : [null], "{$at}.children", $errors, $seen, $depth + 1);

                if ($children !== []) {
                    $clean['children'] = $children;
                }
            }

            $out[] = $clean;
        }

        return $out;
    }

    private static function safeUrl(string $url): bool
    {
        if (preg_match('~^(/(?!/)|#|\?)~', $url) === 1 || $url === '/') {
            return true;
        }

        return preg_match('~^(https?://[^\s]+|mailto:[^\s]+|tel:[+0-9 ()-]+)$~i', $url) === 1;
    }

    /**
     * @param  array<mixed>  $branches
     * @return list<string>
     */
    protected function entryIdsIn(array $branches): array
    {
        $ids = [];

        foreach ($branches as $branch) {
            if (filled($branch['entry'] ?? null)) {
                $ids[] = (string) $branch['entry'];
            }

            array_push($ids, ...$this->entryIdsIn((array) ($branch['children'] ?? [])));
        }

        return $ids;
    }

    /**
     * The proposed tree as the CP shows it: every branch at its depth, removed branches where they stood, each marked added, removed, renamed or moved.
     *
     * @param  array<mixed>  $from
     * @param  array<mixed>  $to
     * @return array{rows: list<array{depth: int, label: string, url: ?string, status: 'added'|'removed'|'changed'|'origin'|null, moved: bool, was: ?string, wasUrl: ?string}>, counts: array<string, int>}
     */
    public function review(array $from, array $to): array
    {
        $this->entries = [];
        $old = $this->flatten($from);
        $new = self::pairRepeats($old, self::matchLinks($old, $this->flatten($to)));
        $oldChildren = self::byParent($old);
        $newChildren = self::byParent($new);
        $moved = [];

        foreach ($newChildren as $parent => $children) {
            $stayed = array_values(array_filter($children, fn (string $key) => ($old[$key]['parent'] ?? null) === (string) $parent));
            $staying = array_flip($stayed);

            foreach ($children as $key) {
                if (isset($old[$key]) && $old[$key]['parent'] !== (string) $parent) {
                    $moved[$key] = true;
                }
            }

            foreach (Moves::moved(array_values(array_filter($oldChildren[$parent] ?? [], fn (string $key) => isset($staying[$key]))), $stayed) as $key) {
                $moved[$key] = true;
            }
        }

        $rows = [];
        $this->emit('', 0, $old, $new, $oldChildren, $newChildren, $moved, $rows);
        $counts = ['added' => 0, 'changed' => 0, 'removed' => 0, 'moved' => 0];

        foreach ($rows as $row) {
            if (isset($counts[$row['status'] ?? ''])) {
                $counts[$row['status']]++;
            }

            if ($row['moved']) {
                $counts['moved']++;
            }
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /** Joins a repeated branch's key to its number; it cannot occur in a title or url. */
    private const REPEAT = "\x1F";

    /** @var array<string, array{label: string, url: ?string}|null> entries looked up during one review */
    private array $entries = [];

    /**
     * @param  array<string, array{parent: string, label: string, url: ?string, sig: ?string}>  $old
     * @param  array<string, array{parent: string, label: string, url: ?string, sig: ?string}>  $new
     * @param  array<string, list<string>>  $oldChildren
     * @param  array<string, list<string>>  $newChildren
     * @param  array<string, true>  $moved
     * @param  list<array<string, mixed>>  $rows
     */
    private function emit(string $parent, int $depth, array $old, array $new, array $oldChildren, array $newChildren, array $moved, array &$rows): void
    {
        // A removed or moved branch shows where it stood: after the last sibling before it that stays in place.
        $removedAfter = [];
        $previous = '';

        foreach ($oldChildren[$parent] ?? [] as $key) {
            // A branch that left a parent shown away from its old place shows where it stood, under the parent's mark.
            if (! self::inPlace($parent, $old, $new, $moved) && isset($new[$key]) && $new[$key]['parent'] !== $parent) {
                continue;
            }

            if (($new[$key]['parent'] ?? null) === $parent && ! isset($moved[$key])) {
                $previous = $key;
            } else {
                $removedAfter[$previous][] = $key;
            }
        }

        $this->emitRemoved($removedAfter[''] ?? [], $depth, $old, $new, $oldChildren, $moved, $rows);

        foreach ($newChildren[$parent] ?? [] as $key) {
            $was = $old[$key] ?? null;
            $now = $new[$key];
            $renamed = $was !== null && $was['label'] !== $now['label'];
            $relinked = $was !== null && $was['url'] !== $now['url'];
            $status = match (true) {
                $was === null => 'added',
                $renamed || $relinked => 'changed',
                default => null,
            };

            $rows[] = [
                'depth' => $depth,
                'label' => $now['label'],
                'url' => $now['url'],
                'status' => $status,
                'moved' => isset($moved[$key]),
                'was' => $renamed ? $was['label'] : null,
                'wasUrl' => $relinked ? ($was['url'] ?? __('no link')) : null,
            ];
            $this->emit($key, $depth + 1, $old, $new, $oldChildren, $newChildren, $moved, $rows);
            $this->emitRemoved($removedAfter[$key] ?? [], $depth, $old, $new, $oldChildren, $moved, $rows);
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, array{parent: string, label: string, url: ?string, sig: ?string}>  $old
     * @param  array<string, array{parent: string, label: string, url: ?string, sig: ?string}>  $new
     * @param  array<string, list<string>>  $oldChildren
     * @param  array<string, true>  $moved
     * @param  list<array<string, mixed>>  $rows
     */
    private function emitRemoved(array $keys, int $depth, array $old, array $new, array $oldChildren, array $moved, array &$rows): void
    {
        foreach ($keys as $key) {
            // A moved branch leaves a mark where it stood, with the children that left it; the rest travel with it.
            if (isset($new[$key])) {
                $rows[] = ['depth' => $depth, 'label' => $old[$key]['label'], 'url' => $old[$key]['url'], 'status' => 'origin', 'moved' => false, 'was' => null, 'wasUrl' => null];
                $this->emitRemoved(array_values(array_filter($oldChildren[$key] ?? [], fn (string $child) => isset($new[$child]) && ($new[$child]['parent'] !== $key || self::hasLeaver($child, $new, $oldChildren, $moved)))), $depth + 1, $old, $new, $oldChildren, $moved, $rows);

                continue;
            }

            $rows[] = ['depth' => $depth, 'label' => $old[$key]['label'], 'url' => $old[$key]['url'], 'status' => 'removed', 'moved' => false, 'was' => null, 'wasUrl' => null];
            $this->emitRemoved($oldChildren[$key] ?? [], $depth + 1, $old, $new, $oldChildren, $moved, $rows);
        }
    }

    /**
     * Whether a branch, and every branch above it, stands where it stood.
     *
     * @param  array<string, array{parent: string}>  $old
     * @param  array<string, array{parent: string}>  $new
     * @param  array<string, true>  $moved
     */
    private static function inPlace(string $key, array $old, array $new, array $moved): bool
    {
        for (; $key !== ''; $key = $old[$key]['parent']) {
            if (! isset($old[$key], $new[$key]) || isset($moved[$key]) || $new[$key]['parent'] !== $old[$key]['parent']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a branch that travelled with its parent held a branch that left, so its old place is shown to mark where that one stood.
     *
     * @param  array<string, array{parent: string}>  $new
     * @param  array<string, list<string>>  $oldChildren
     * @param  array<string, true>  $moved
     */
    private static function hasLeaver(string $key, array $new, array $oldChildren, array $moved): bool
    {
        if (isset($moved[$key])) {
            return false;
        }

        foreach ($oldChildren[$key] ?? [] as $child) {
            if (isset($new[$child]) && ($new[$child]['parent'] !== $key || self::hasLeaver($child, $new, $oldChildren, $moved))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array{parent: string}>  $flat
     * @return array<string, list<string>>
     */
    private static function byParent(array $flat): array
    {
        $children = [];

        foreach ($flat as $key => $branch) {
            $children[$branch['parent']][] = (string) $key;
        }

        return $children;
    }

    /**
     * A link branch the stored tree holds without an id gets one when a proposal is validated; it is the same branch when its link and title match.
     *
     * @param  array<string, array{parent: string, label: string, url: ?string, sig: ?string}>  $old
     * @param  array<string, array{parent: string, label: string, url: ?string, sig: ?string}>  $new
     * @return array<string, array{parent: string, label: string, url: ?string, sig: ?string}>
     */
    private static function matchLinks(array $old, array $new): array
    {
        $unmatched = [];

        foreach ($old as $key => $branch) {
            if (str_starts_with((string) $key, 'link:') && ! isset($new[$key])) {
                $unmatched[$branch['sig']][] = (string) $key;
            }
        }

        $rename = [];

        foreach ($new as $key => $branch) {
            if (! isset($old[$key]) && $branch['sig'] !== null && ($unmatched[$branch['sig']] ?? []) !== []) {
                $rename[$key] = array_shift($unmatched[$branch['sig']]);
            }
        }

        if ($rename === []) {
            return $new;
        }

        $out = [];

        foreach ($new as $key => $branch) {
            $branch['parent'] = $rename[$branch['parent']] ?? $branch['parent'];
            $out[$rename[$key] ?? $key] = $branch;
        }

        return $out;
    }

    /**
     * A page or link the tree holds more than once is the same branch where it keeps its parent, so a repeat that is dropped reads as removed, not as the other one moving.
     *
     * @param  array<string, array{parent: string, label: string, url: ?string, sig: ?string}>  $old
     * @param  array<string, array{parent: string, label: string, url: ?string, sig: ?string}>  $new
     * @return array<string, array{parent: string, label: string, url: ?string, sig: ?string}>
     */
    private static function pairRepeats(array $old, array $new): array
    {
        $base = fn (string $key) => explode(self::REPEAT, $key)[0];
        $groups = [];

        foreach ([...array_keys($old), ...array_keys($new)] as $key) {
            $groups[$base((string) $key)][(string) $key] = true;
        }

        $groups = array_filter($groups, fn (array $keys) => count($keys) > 1);

        if ($groups === []) {
            return $new;
        }

        $free = array_map(fn (array $keys) => array_values(array_filter(array_keys($keys), fn (string $key) => isset($old[$key]))), $groups);
        $path = function (array $flat, string $key) use ($base): string {
            $path = [];

            for ($at = $flat[$key]['parent']; $at !== '' && isset($flat[$at]); $at = $flat[$at]['parent']) {
                $path[] = $base($at);
            }

            return implode(self::REPEAT, $path);
        };
        $parent = fn (array $flat, string $key) => $base($flat[$key]['parent']);
        $rename = [];

        // A repeat is the one under the same pages above it, else under the same parent page, else the next one left.
        foreach ([$path, $parent, fn () => ''] as $where) {
            foreach (array_keys($new) as $key) {
                $key = (string) $key;
                $group = $base($key);

                if (! isset($groups[$group]) || isset($rename[$key])) {
                    continue;
                }

                $wanted = $where($new, $key);

                foreach ($free[$group] as $index => $candidate) {
                    if ($where($old, $candidate) === $wanted) {
                        $rename[$key] = $candidate;
                        array_splice($free[$group], $index, 1);

                        break;
                    }
                }
            }
        }

        foreach (array_keys($new) as $key) {
            if (isset($groups[$base((string) $key)]) && ! isset($rename[$key])) {
                $rename[$key] = $base((string) $key).self::REPEAT.'new'.count($rename);
            }
        }

        $out = [];

        foreach ($new as $key => $branch) {
            $branch['parent'] = $rename[$branch['parent']] ?? $branch['parent'];
            $out[$rename[$key] ?? $key] = $branch;
        }

        return $out;
    }

    /**
     * Every branch keyed by what it is (its entry, else its id, else its link and title), a repeat numbered so it keeps its own place, in tree order.
     *
     * @param  array<mixed>  $branches
     * @param  array<string, int>  $seen
     * @return array<string, array{parent: string, label: string, url: ?string, sig: ?string}>
     */
    private function flatten(array $branches, string $parent = '', array &$seen = []): array
    {
        $flat = [];

        foreach ($branches as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            $text = fn (string $field) => is_scalar($branch[$field] ?? null) && filled($branch[$field]) ? (string) $branch[$field] : null;
            $entry = $text('entry') === null ? null : $this->entry($text('entry'));
            $url = $text('url');
            $sig = $text('entry') === null ? ($url ?? '').'|'.($text('title') ?? '') : null;
            $base = match (true) {
                $text('entry') !== null => 'entry:'.$text('entry'),
                $text('id') !== null => 'id:'.$text('id'),
                default => 'link:'.$sig,
            };
            $base = str_replace(self::REPEAT, '', $base);
            $seen[$base] = ($seen[$base] ?? 0) + 1;
            $key = $seen[$base] === 1 ? $base : $base.self::REPEAT.$seen[$base];

            $flat[$key] = [
                'parent' => $parent,
                'label' => $text('title') ?? $entry['label'] ?? $url ?? ($text('entry') ?? ''),
                'url' => $entry === null ? $url : $entry['url'],
                'sig' => $sig,
            ];

            if (is_array($branch['children'] ?? null)) {
                $flat += $this->flatten($branch['children'], $key, $seen);
            }
        }

        return $flat;
    }

    /** @return array{label: string, url: ?string}|null */
    private function entry(string $id): ?array
    {
        if (! array_key_exists($id, $this->entries)) {
            $entry = Entry::find($id);
            $this->entries[$id] = $entry === null ? null : ['label' => (string) $entry->value('title'), 'url' => $entry->url()];
        }

        return $this->entries[$id];
    }

    public function outline(array $value): array
    {
        $lines = [];
        $this->walk($value, '', $lines);

        return $lines;
    }

    /**
     * @param  array<mixed>  $branches
     * @param  array<string, string>  $lines
     */
    private function walk(array $branches, string $prefix, array &$lines): void
    {
        foreach (array_values($branches) as $index => $branch) {
            $at = ltrim($prefix.'.'.($index + 1), '.');
            $entry = filled($branch['entry'] ?? null) ? Entry::find((string) $branch['entry']) : null;
            $title = $branch['title'] ?? $entry?->value('title') ?? $branch['url'] ?? (string) ($branch['entry'] ?? '');
            $suffix = filled($branch['url'] ?? null) && $entry === null ? " ({$branch['url']})" : '';
            $lines[$at] = $title.$suffix;

            if (is_array($branch['children'] ?? null)) {
                $this->walk($branch['children'], $at, $lines);
            }
        }
    }
}
