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
