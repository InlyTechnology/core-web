<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Support\Collection as LaravelCollection;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Structures\CollectionTreeRepository;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalVariables;
use Statamic\Facades\Nav;
use Statamic\Facades\Term;

/**
 * The site's content as the files the Stache would write for it, a page at a
 * time, for a machine pulling it: entries (published or not, every site),
 * terms, global variables, collection trees and nav trees. It walks
 * Statamic's repositories, so it reads the same from flat files as from the
 * Eloquent driver, and each item's `contents` is what the Stache writes for
 * it (`fileContents()`), at the path the default stores lay it out under
 * content/. Only what the user may view is in it. Blueprints, fieldsets,
 * config, users, submissions and assets are not content to sync.
 */
class ContentExport
{
    /**
     * @return array{data: list<array{type: string, id: string, path: string, contents: string, hash: string}>, next_cursor: ?string}
     */
    public function page(User $user, string $type, ?string $handle, ?string $site, int $offset, int $size): array
    {
        ContentPaths::assertType($type);

        $candidates = $this->candidates($type, $handle, $site, $offset, $size + 1);
        $more = count($candidates) > $size;

        $data = collect(array_slice($candidates, 0, $size))
            ->filter(fn (array $item) => $user->can('view', $item['permission']))
            ->map(fn (array $item) => $this->item($type, $item))
            ->filter()
            ->values()
            ->all();

        return ['data' => $data, 'next_cursor' => $more ? (string) ($offset + $size) : null];
    }

    /**
     * Each candidate with what it is checked against: the item, or for a
     * collection tree its collection, whose view permission covers its order.
     *
     * @return list<array{id: string, item: object, permission: object}>
     */
    private function candidates(string $type, ?string $handle, ?string $site, int $offset, int $limit): array
    {
        if ($type === 'entries') {
            return $this->entries($handle, $site, $offset, $limit);
        }

        $all = match ($type) {
            'terms' => $this->terms($handle),
            'globals' => $this->globals($handle, $site),
            'collection-trees' => $this->collectionTrees($handle, $site),
            'nav-trees' => $this->navTrees($handle, $site),
        };

        return $all->sortBy('id')->slice($offset, $limit)->values()->all();
    }

    /** @return list<array{id: string, item: object, permission: object}> */
    private function entries(?string $collection, ?string $site, int $offset, int $limit): array
    {
        $query = Entry::query();

        if ($collection !== null) {
            $query->where('collection', $collection);
        }

        if ($site !== null) {
            $query->where('site', $site);
        }

        return $query->orderBy('id')->offset($offset)->limit($limit)->get()
            ->map(fn ($entry) => ['id' => (string) $entry->id(), 'item' => $entry, 'permission' => $entry])
            ->values()
            ->all();
    }

    /** A term's file holds every site's localizations, so it is one item whatever the site. */
    private function terms(?string $taxonomy): LaravelCollection
    {
        $query = Term::query();

        if ($taxonomy !== null) {
            $query->where('taxonomy', $taxonomy);
        }

        return $query->get()
            ->map(fn ($term) => method_exists($term, 'term') ? $term->term() : $term)
            ->unique(fn ($term) => $term->id())
            ->map(fn ($term) => ['id' => (string) $term->id(), 'item' => $term, 'permission' => $term]);
    }

    private function globals(?string $set, ?string $site): LaravelCollection
    {
        return collect(GlobalVariables::all())
            ->filter(fn ($variables) => ($set === null || $variables->handle() === $set) && ($site === null || $variables->locale() === $site))
            ->map(fn ($variables) => ['id' => $variables->handle().'::'.$variables->locale(), 'item' => $variables, 'permission' => $variables]);
    }

    /** Only trees that are stored: a structured collection answers an empty tree for a site it has none in. */
    private function collectionTrees(?string $handle, ?string $site): LaravelCollection
    {
        $trees = app(CollectionTreeRepository::class);

        return Collection::all()
            ->filter(fn ($collection) => $collection->hasStructure() && ($handle === null || $collection->handle() === $handle))
            ->flatMap(fn ($collection) => $collection->sites()
                ->filter(fn ($handle) => $site === null || $handle === $site)
                ->map(fn ($handle) => $trees->find($collection->handle(), $handle))
                ->filter()
                ->map(fn ($tree) => ['id' => $collection->handle().'::'.$tree->locale(), 'item' => $tree, 'permission' => $collection])
                ->values()
                ->all());
    }

    private function navTrees(?string $handle, ?string $site): LaravelCollection
    {
        return Nav::all()
            ->filter(fn ($nav) => $handle === null || $nav->handle() === $handle)
            ->flatMap(fn ($nav) => $nav->trees()
                ->filter(fn ($tree) => $site === null || $tree->locale() === $site)
                ->map(fn ($tree) => ['id' => $nav->handle().'::'.$tree->locale(), 'item' => $tree, 'permission' => $tree])
                ->values()
                ->all());
    }

    /**
     * @param  array{id: string, item: object, permission: object}  $candidate
     * @return array{type: string, id: string, path: string, contents: string, hash: string}|null
     */
    private function item(string $type, array $candidate): ?array
    {
        $path = ContentPaths::relative($type, (string) $candidate['item']->path());

        if ($path === null) {
            return null;
        }

        $contents = (string) $candidate['item']->fileContents();

        return ['type' => $type, 'id' => $candidate['id'], 'path' => $path, 'contents' => $contents, 'hash' => hash('sha256', $contents)];
    }
}
