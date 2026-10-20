<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Support\Collection as LaravelCollection;
use Statamic\Contracts\Auth\User;
use Statamic\Contracts\Structures\CollectionTreeRepository;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\GlobalVariables;
use Statamic\Facades\Nav;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

/**
 * The site's content as the files the Stache would write for it, a page at a
 * time, for a machine pulling it: entries (published or not, every site),
 * terms, global variables, collection trees and nav trees. It walks
 * Statamic's repositories, so it reads the same from flat files as from the
 * Eloquent driver, and each item's `contents` is what the Stache writes for
 * it (`fileContents()`, less what only the Eloquent driver writes), at the
 * path the default stores lay it out under content/. Only what the user may
 * view is in it, and each page says which scopes that is all of. Blueprints,
 * fieldsets, config, users, submissions and assets are not content to sync.
 */
class ContentExport
{
    /**
     * A page of items, with what it covers: the handles and sites whose every
     * item the user may view, so a pull deletes a missing file only there.
     * `covers_all` stands for every scope there is, even one the site no
     * longer has; it holds for a super user while no item was held back.
     *
     * @return array{data: list<array{type: string, id: string, path: string, contents: string, hash: string}>, next_cursor: ?string, covers_all: bool, covers: list<array{handle: string, site: ?string}>}
     */
    public function page(User $user, string $type, ?string $handle, ?string $site, int $offset, int $size): array
    {
        ContentPaths::assertType($type);

        $candidates = $this->candidates($type, $handle, $site, $offset, $size + 1);
        $more = count($candidates) > $size;
        $covers = $this->scopes($user, $type, $handle, $site);
        $all = $user->isSuper();
        $data = [];

        foreach (array_slice($candidates, 0, $size) as $candidate) {
            $path = ContentPaths::relative($type, (string) $candidate['item']->path());
            $scope = $path === null ? null : ContentPaths::parse($type, $path);

            if ($scope !== null && $this->allowed($user, $type, $scope['handle'], $scope['site']) && $user->can('view', $candidate['permission'])) {
                $data[] = $this->item($type, $candidate, $path);

                continue;
            }

            // A policy held back an item its scope's permissions allow: that scope is no longer whole.
            if ($scope !== null) {
                $all = false;
                $covers = array_values(array_filter($covers, fn (array $cover) => $cover !== $scope));
            }
        }

        return ['data' => $data, 'next_cursor' => $more ? (string) ($offset + $size) : null, 'covers_all' => $all, 'covers' => $covers];
    }

    /**
     * The scopes of a type the user may read in full, by the permissions alone.
     *
     * @return list<array{handle: string, site: ?string}>
     */
    private function scopes(User $user, string $type, ?string $handle, ?string $site): array
    {
        $handles = match ($type) {
            'entries', 'collection-trees' => Collection::handles(),
            'terms' => Taxonomy::handles(),
            'globals' => GlobalSet::all()->map->handle(),
            'nav-trees' => Nav::all()->map->handle(),
        };

        $sites = $type === 'terms' ? [null] : Site::all()->keys()->all();
        $scopes = [];

        foreach (collect($handles)->map(fn ($each) => (string) $each)->filter(fn (string $each) => $handle === null || $each === $handle) as $each) {
            foreach ($sites as $siteHandle) {
                if (($site === null || $siteHandle === null || $siteHandle === $site) && $this->allowed($user, $type, $each, $siteHandle)) {
                    $scopes[] = ['handle' => $each, 'site' => $siteHandle];
                }
            }
        }

        return $scopes;
    }

    /**
     * Whether the user may view every item in a scope, read from the
     * permissions Statamic's own policies check. A permission a policy would
     * also accept but this does not list only makes a pull keep files.
     */
    private function allowed(User $user, string $type, string $handle, ?string $site): bool
    {
        if ($user->isSuper()) {
            return true;
        }

        if ($site !== null) {
            $siteObject = Site::get($site);

            if ($siteObject === null || ! $user->can('view', $siteObject)) {
                return false;
            }
        }

        return $user->hasPermission(match ($type) {
            'entries', 'collection-trees' => "view {$handle} entries",
            'terms' => "view {$handle} terms",
            'globals' => "edit {$handle} globals",
            'nav-trees' => "view {$handle} nav",
        });
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
     * @return array{type: string, id: string, path: string, contents: string, hash: string}
     */
    private function item(string $type, array $candidate, string $path): array
    {
        $contents = ContentFile::canonical($type, $path, (string) $candidate['item']->fileContents());

        return ['type' => $type, 'id' => $candidate['id'], 'path' => $path, 'contents' => $contents, 'hash' => hash('sha256', $contents)];
    }
}
