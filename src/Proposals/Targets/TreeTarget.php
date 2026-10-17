<?php

namespace Inly\CoreWeb\Proposals\Targets;

use Illuminate\Validation\ValidationException;
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
    abstract protected function tree(): Tree;

    /** Whether a branch may be a URL rather than an entry (a navigation's may, a page tree's may not). */
    abstract protected function allowsUrls(): bool;

    /** @return list<string> the collections a branch's entry may come from */
    abstract protected function collections(): array;

    public function current(): array
    {
        return $this->tree()->tree();
    }

    public function validate(array $value): array
    {
        $errors = [];
        $seen = [];
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

            $clean = array_intersect_key($branch, array_flip(['id', 'entry', 'url', 'title', 'data']));

            if (filled($branch['entry'] ?? null)) {
                $entry = Entry::find((string) $branch['entry']);

                if (! $entry || ! in_array($entry->collectionHandle(), $this->collections(), true) || $entry->locale() !== $this->tree()->locale()) {
                    $errors["{$at}.entry"][] = "No entry `{$branch['entry']}` this tree can hold.";
                } elseif (isset($seen[$entry->id()])) {
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
