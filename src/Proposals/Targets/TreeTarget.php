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
     * @return array{rows: list<array{depth: int, label: string, url: ?string, status: 'added'|'removed'|'changed'|null, moved: bool, was: ?string}>, counts: array<string, int>}
     */
    public function review(array $from, array $to): array
    {
        $old = [];
        $new = [];
        $this->flatten($from, '', $old);
        $this->flatten($to, '', $new);

        $moved = [];
        $parents = array_unique(['', ...array_keys($new)]);

        foreach ($parents as $parent) {
            $children = self::childrenOf($new, $parent);
            $stayed = array_values(array_filter($children, fn (string $key) => ($old[$key]['parent'] ?? null) === $parent));
            $before = array_values(array_filter(self::childrenOf($old, $parent), fn (string $key) => in_array($key, $stayed, true)));

            foreach ($children as $key) {
                if (isset($old[$key]) && $old[$key]['parent'] !== $parent) {
                    $moved[$key] = true;
                }
            }

            foreach (Moves::moved($before, $stayed) as $key) {
                $moved[$key] = true;
            }
        }

        $rows = [];
        $this->emit('', 0, $old, $new, $moved, $rows);
        $counts = ['added' => 0, 'changed' => 0, 'removed' => 0, 'moved' => 0];

        foreach ($rows as $row) {
            if ($row['status'] !== null) {
                $counts[$row['status']]++;
            }

            if ($row['moved']) {
                $counts['moved']++;
            }
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * @param  array<string, array{parent: string, label: string, url: ?string}>  $old
     * @param  array<string, array{parent: string, label: string, url: ?string}>  $new
     * @param  array<string, true>  $moved
     * @param  list<array<string, mixed>>  $rows
     */
    private function emit(string $parent, int $depth, array $old, array $new, array $moved, array &$rows): void
    {
        // A removed branch follows the last sibling before it that is still under this parent, so it shows where it stood.
        $removedAfter = [];
        $previous = '';

        foreach (self::childrenOf($old, $parent) as $key) {
            if (($new[$key]['parent'] ?? null) === $parent) {
                $previous = $key;
            } elseif (! isset($new[$key])) {
                $removedAfter[$previous][] = $key;
            }
        }

        $this->emitRemoved($removedAfter[''] ?? [], $depth, $old, $new, $rows);

        foreach (self::childrenOf($new, $parent) as $key) {
            $was = $old[$key] ?? null;
            $now = $new[$key];
            $status = match (true) {
                $was === null => 'added',
                $was['label'] !== $now['label'] || $was['url'] !== $now['url'] => 'changed',
                default => null,
            };

            $rows[] = ['depth' => $depth, 'label' => $now['label'], 'url' => $now['url'], 'status' => $status, 'moved' => isset($moved[$key]), 'was' => $status === 'changed' ? $was['label'].($was['url'] !== $now['url'] && $was['url'] !== null ? " ({$was['url']})" : '') : null];
            $this->emit($key, $depth + 1, $old, $new, $moved, $rows);
            $this->emitRemoved($removedAfter[$key] ?? [], $depth, $old, $new, $rows);
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, array{parent: string, label: string, url: ?string}>  $old
     * @param  array<string, array{parent: string, label: string, url: ?string}>  $new
     * @param  list<array<string, mixed>>  $rows
     */
    private function emitRemoved(array $keys, int $depth, array $old, array $new, array &$rows): void
    {
        foreach ($keys as $key) {
            $rows[] = ['depth' => $depth, 'label' => $old[$key]['label'], 'url' => $old[$key]['url'], 'status' => 'removed', 'moved' => false, 'was' => null];
            // Children that live on elsewhere in the proposed tree show there; the rest go with their parent.
            $this->emitRemoved(array_values(array_filter(self::childrenOf($old, $key), fn (string $child) => ! isset($new[$child]))), $depth + 1, $old, $new, $rows);
        }
    }

    /**
     * @param  array<string, array{parent: string}>  $flat
     * @return list<string>
     */
    private static function childrenOf(array $flat, string $parent): array
    {
        return array_keys(array_filter($flat, fn (array $branch) => $branch['parent'] === $parent));
    }

    /**
     * Every branch keyed by what it is (its entry, else its id, else its link), in tree order.
     *
     * @param  array<mixed>  $branches
     * @param  array<string, array{parent: string, label: string, url: ?string}>  $flat
     */
    private function flatten(array $branches, string $parent, array &$flat): void
    {
        foreach (array_values($branches) as $index => $branch) {
            if (! is_array($branch)) {
                continue;
            }

            $entry = filled($branch['entry'] ?? null) ? Entry::find((string) $branch['entry']) : null;
            $key = match (true) {
                filled($branch['entry'] ?? null) => 'entry:'.$branch['entry'],
                filled($branch['id'] ?? null) => 'id:'.$branch['id'],
                default => 'link:'.($branch['url'] ?? '').'|'.($branch['title'] ?? '').'|'.$parent.'|'.$index,
            };
            $flat[$key] = [
                'parent' => $parent,
                'label' => (string) ($branch['title'] ?? $entry?->value('title') ?? $branch['url'] ?? ($branch['entry'] ?? '')),
                'url' => $entry ? $entry->url() : (filled($branch['url'] ?? null) ? (string) $branch['url'] : null),
            ];

            if (is_array($branch['children'] ?? null)) {
                $this->flatten($branch['children'], $key, $flat);
            }
        }
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
