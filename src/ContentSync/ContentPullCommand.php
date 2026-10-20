<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Console\Command;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Media\MediaDisks;
use InvalidArgumentException;
use Statamic\Contracts\Assets\AssetContainer;
use Statamic\Facades\Site;

/**
 * Pulls the live site's content into this checkout's content/ as the files
 * the Stache writes, signed in as the developer's CMS user. content.lock
 * holds the hash of every file as last pulled, so a file the developer
 * changed since is left alone (and listed) unless --force, and a file the
 * site deleted goes only when it was not changed here either, and only in a
 * scope the site says the developer may read in full. The site's asset
 * index (AssetPull) is pulled beside it, replaced whole each time.
 */
class ContentPullCommand extends Command
{
    use SignsInToRemoteSite;

    protected $signature = 'content:pull
        {--only= : Types to pull, comma separated, each optionally narrowed to a handle (entries:pages,globals,assets:assets); every type when omitted}
        {--site= : Only this site\'s content}
        {--force : Overwrite or delete files changed here since the last pull (or, on a first pull, since the last commit)}
        {--dry-run : Show what would change and write nothing}';

    protected $description = 'Pull the remote site\'s content (core-web.content_sync.remote_url) into content/';

    private const MAX_PAGES = 10000;

    public function handle(LocalContent $local, AssetPull $assets): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('content:pull overwrites content/ with another site\'s content, so it runs only where APP_ENV is local.');

            return self::FAILURE;
        }

        try {
            $scopes = $this->scopes();
            $containers = $this->assetContainers($assets, $scopes);
        } catch (InvalidArgumentException|RemoteSiteError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $contentScopes = array_values(array_filter($scopes, fn (array $scope) => $scope['type'] !== AssetExport::TYPE));
        $site = $this->option('site');

        if (is_string($site) && $site !== '' && Site::get($site) === null) {
            $this->error("No site [{$site}]. Known: ".Site::all()->keys()->implode(', ').'.');

            return self::FAILURE;
        }

        $site = is_string($site) && $site !== '' ? $site : null;

        $fresh = RemoteSite::fromStorage() === null;
        $remote = $this->remote();

        if ($remote === null) {
            return self::FAILURE;
        }

        $fetch = fn (RemoteSite $remote): array => [
            ...$this->fetch($local, $remote, $contentScopes, $site),
            $this->fetchAssets($assets, $remote, $containers),
        ];

        try {
            try {
                [$items, $coverage, $indexes] = $fetch($remote);
            } catch (RemoteLoginNeeded $e) {
                if ($fresh) {
                    throw $e;
                }

                if (($remote = $this->signInAgain()) === null) {
                    return self::FAILURE;
                }

                [$items, $coverage, $indexes] = $fetch($remote);
            }
        } catch (RemoteLoginNeeded) {
            $this->refusedFreshLogin();

            return self::FAILURE;
        } catch (RemoteSiteError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $wrote = $contentScopes !== [] && $this->apply($local, $remote, $contentScopes, $site, $items, $coverage);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($indexes !== null) {
            $this->applyAssets($assets, $remote, $containers, $indexes);
        }

        // AssetPull clears what Statamic cached of the containers itself, so only content needs the Stache rebuilt.
        if ($wrote) {
            $local->refreshStache();
        }

        if ($this->option('dry-run')) {
            $this->line('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * The local asset containers the pull fills, when it pulls assets; naming assets where no disk reads the site's index says so.
     *
     * @param  list<array{type: string, handle: ?string}>  $scopes
     * @return list<AssetContainer>
     */
    private function assetContainers(AssetPull $assets, array $scopes): array
    {
        $containers = [];
        $named = trim((string) $this->option('only')) !== '';

        foreach ($scopes as $scope) {
            if ($scope['type'] !== AssetExport::TYPE) {
                continue;
            }

            $found = $assets->containers($scope['handle']);

            foreach ($found['pull'] as $container) {
                $containers[$container->handle()] = $container;
            }

            // A site without core-web's media pulls no assets by design; one with it hears why none came.
            if (! $named && $found['pull'] === [] && CoreWebServiceProvider::enabled('media') && filter_var(config('core-web.content_sync.pull_assets', true), FILTER_VALIDATE_BOOLEAN)) {
                $this->line('Assets: not pulled, '.(MediaDisks::bucketConfigured()
                    ? 'this checkout has the media bucket\'s key (core-web.media.bucket.key), so its disks read the bucket itself.'
                    : 'no asset disk here reads the live site\'s index (only a disk in core-web.media.disks, with features.media on, does).'));
            }

            if ($named && ($found['skipped'] !== [] || $found['pull'] === [])) {
                $this->warn('Assets: '.($found['skipped'] === [] ? 'no container pulled' : implode(', ', $found['skipped']).' not pulled').': only a disk in core-web.media.disks, with features.media on a local checkout without the bucket\'s key, reads the live site\'s index.');
            }
        }

        return array_values($containers);
    }

    /**
     * Each container's index; null when the site exports no assets, so every index is kept as it was.
     *
     * @param  list<AssetContainer>  $containers
     * @return list<array{url: ?string, files: array<string, mixed>, dropped: int}|null>|null
     */
    private function fetchAssets(AssetPull $assets, RemoteSite $remote, array $containers): ?array
    {
        try {
            return array_map(fn (AssetContainer $container) => $assets->fetch($remote, $container), $containers);
        } catch (AssetsNotExported $e) {
            $this->warn("Assets: {$e->getMessage()} The asset index here is left as it was; content is pulled as usual.");

            return null;
        }
    }

    /**
     * @param  list<AssetContainer>  $containers
     * @param  list<array{url: ?string, files: array<string, mixed>, dropped: int}|null>  $indexes
     */
    private function applyAssets(AssetPull $assets, RemoteSite $remote, array $containers, array $indexes): void
    {
        foreach ($containers as $i => $container) {
            $index = $indexes[$i];
            $this->line($index === null
                ? "Assets {$container->handle()}: the site has no such container or you may not view it, so its index is cleared."
                : sprintf('Assets %s: %d files indexed; their bytes stay on the site.', $container->handle(), count($index['files'])));

            if (($index['dropped'] ?? 0) > 0) {
                $this->warn(sprintf('Assets %s: %d files left out, their URLs on a host other than the site\'s or the container\'s.', $container->handle(), $index['dropped']));
            }

            if (! $this->option('dry-run')) {
                $assets->apply($remote, $container, $index);
            }
        }
    }

    /**
     * Each type to pull, with the handle it is narrowed to.
     *
     * @return list<array{type: string, handle: ?string}>
     */
    private function scopes(): array
    {
        $only = trim((string) $this->option('only'));

        if ($only === '') {
            return array_map(fn (string $type) => ['type' => $type, 'handle' => null], [...array_keys(ContentPaths::TYPES), AssetExport::TYPE]);
        }

        return array_values(array_map(function (string $part): array {
            [$type, $handle] = array_pad(explode(':', trim($part), 2), 2, null);

            if ($type !== AssetExport::TYPE) {
                ContentPaths::assertType($type);
            }

            return ['type' => $type, 'handle' => blank($handle) ? null : $handle];
        }, array_filter(explode(',', $only), fn (string $part) => trim($part) !== '')));
    }

    private function remote(): ?RemoteSite
    {
        if (RemoteSite::configuredUrl() === '') {
            $this->error('Set core-web.content_sync.remote_url in config/core-web.php: the live site to pull content from.');

            return null;
        }

        return $this->signedInSite();
    }

    /**
     * Every item the site exports in the scopes, by path, each path checked
     * before anything is written: one the type does not lay out there, or one
     * with a way out of content/, stops the pull. Contents are kept only for
     * a file that differs here, so an up-to-date pull holds hashes alone.
     * With them, per scope, what every page agreed it covers.
     *
     * @param  list<array{type: string, handle: ?string}>  $scopes
     * @return array{0: array<string, array{type: string, hash: string, contents: ?string}>, 1: array<string, array{all: bool, scopes: list<array{handle: string, site: ?string}>}>}
     */
    private function fetch(LocalContent $local, RemoteSite $remote, array $scopes, ?string $site): array
    {
        $items = [];
        $coverage = [];

        foreach ($scopes as $scope) {
            $cursor = '0';

            for ($page = 0; $cursor !== null; $page++) {
                if ($page >= self::MAX_PAGES) {
                    throw new RemoteSiteError("The site kept paging {$scope['type']} past ".self::MAX_PAGES.' pages.');
                }

                $response = $remote->get('export', array_filter([
                    'type' => $scope['type'],
                    'handle' => $scope['handle'],
                    'site' => $site,
                    'cursor' => $cursor,
                ], fn (mixed $value) => $value !== null));

                if (! $response->successful() || ! is_array($response->json('data'))) {
                    throw new RemoteSiteError("{$remote->url()} answered HTTP {$response->status()} for {$scope['type']}: ".($response->json('message') ?? $response->json('error') ?? 'no reason given').'.');
                }

                $coverage[self::key($scope)] = self::narrow($coverage[self::key($scope)] ?? null, $response->json('covers_all'), $response->json('covers'));

                foreach ($response->json('data') as $item) {
                    $path = is_array($item) ? ($item['path'] ?? null) : null;
                    $contents = is_array($item) ? ($item['contents'] ?? null) : null;

                    if (! is_string($path) || ! is_string($contents) || ! ContentPaths::inScope($scope['type'], $path, $scope['handle'], $site)) {
                        throw new RemoteSiteError("The site sent a {$scope['type']} item at [".(is_string($path) ? $path : '?').'], which is no path of its own inside content/; nothing was written.');
                    }

                    $contents = ContentFile::canonical($scope['type'], $path, $contents);
                    $hash = hash('sha256', $contents);
                    $items[$path] = ['type' => $scope['type'], 'hash' => $hash, 'contents' => self::localHash($local, $path) === $hash ? null : $contents];
                }

                $next = $response->json('next_cursor');
                $cursor = is_scalar($next) && (string) $next !== '' ? (string) $next : null;
            }
        }

        return [$items, $coverage];
    }

    /** A path that leads out of content/ is refused when the plan is made, with the rest. */
    private static function localHash(LocalContent $local, string $path): ?string
    {
        try {
            return $local->hash($path);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function localContents(LocalContent $local, string $path): ?string
    {
        try {
            return $local->read($path);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * What a scope's pages agree they cover: a page that holds an item back
     * narrows it for the whole pull, and an answer without coverage covers nothing.
     *
     * @param  array{all: bool, scopes: list<array{handle: string, site: ?string}>}|null  $soFar
     * @return array{all: bool, scopes: list<array{handle: string, site: ?string}>}
     */
    private static function narrow(?array $soFar, mixed $all, mixed $covers): array
    {
        $page = ['all' => $all === true, 'scopes' => []];

        foreach (is_array($covers) ? $covers : [] as $cover) {
            $site = is_array($cover) ? ($cover['site'] ?? null) : null;

            if (is_array($cover) && is_string($cover['handle'] ?? null) && ($site === null || is_string($site))) {
                $page['scopes'][] = ['handle' => $cover['handle'], 'site' => $site];
            }
        }

        if ($soFar === null) {
            return $page;
        }

        if ($page['all']) {
            return $soFar;
        }

        if ($soFar['all']) {
            return $page;
        }

        return ['all' => false, 'scopes' => array_values(array_filter($soFar['scopes'], fn (array $scope) => in_array($scope, $page['scopes'], true)))];
    }

    /**
     * @param  list<array{type: string, handle: ?string}>  $scopes
     * @param  array<string, array{type: string, hash: string, contents: ?string}>  $items
     * @param  array<string, array{all: bool, scopes: list<array{handle: string, site: ?string}>}>  $coverage
     */
    private function apply(LocalContent $local, RemoteSite $remote, array $scopes, ?string $site, array $items, array $coverage): bool
    {
        $lock = $local->lock()['items'];
        $plan = ['new' => [], 'changed' => [], 'deleted' => [], 'skipped' => [], 'kept' => [], 'unread' => []];
        $writes = [];
        $deletes = [];
        $force = (bool) $this->option('force');

        foreach ($items as $path => $item) {
            $current = $local->hash($path);
            $locked = $lock[$path] ?? null;

            if ($current === $item['hash']) {
                $lock[$path] = $item['hash'];

                continue;
            }

            // Contents are not kept for a file that matched while fetching; it changed here since.
            if ($item['contents'] === null) {
                $plan['skipped'][] = $path;

                continue;
            }

            $contents = $current === null ? null : self::localContents($local, $path);

            // Key order or a save stamp alone (a site on the Eloquent driver) is no change: this file stays as it is.
            if ($contents !== null && ContentFile::same($path, $item['contents'], $contents)) {
                $lock[$path] = $current;

                continue;
            }

            // No lock entry yet (a first pull): a file as the last commit has it was not changed here.
            $changedHere = $current !== null && $current !== $locked && ! ($locked === null && $local->matchesCommitted($path));

            if ($changedHere && ! $force) {
                $plan['skipped'][] = $path;

                continue;
            }

            $local->assertWritable($path);
            $plan[$current === null ? 'new' : 'changed'][] = $path;
            $writes[$path] = ContentFile::merge($item['type'], $path, $item['contents'], $contents);
            $lock[$path] = hash('sha256', $writes[$path]);
        }

        foreach (array_keys($lock) as $path) {
            $scope = isset($items[$path]) ? null : $this->scopeOf($scopes, $site, $path);

            if ($scope === null) {
                continue;
            }

            $current = $local->hash($path);

            if ($current === null) {
                unset($lock[$path]);
            } elseif (! self::readable($coverage[self::key($scope)] ?? null, $scope['type'], $path)) {
                // Missing from the export may only mean hidden from this user: never a reason to delete.
                $plan['unread'][] = $path;
            } elseif ($current === $lock[$path] || $force) {
                $local->assertWritable($path);
                $plan['deleted'][] = $path;
                $deletes[] = $path;
                unset($lock[$path]);
            } else {
                $plan['kept'][] = $path;
            }
        }

        $this->report($plan);

        if ($this->option('dry-run')) {
            return false;
        }

        foreach ($writes as $path => $contents) {
            $local->write($path, $contents);
        }

        foreach ($deletes as $path) {
            $local->delete($path);
        }

        $local->writeLock($remote->url(), $lock);

        $this->info(sprintf('Pulled from %s: %d new, %d changed, %d deleted.', $remote->url(), count($plan['new']), count($plan['changed']), count($plan['deleted'])));

        return $writes !== [] || $deletes !== [];
    }

    /**
     * The pulled scope a locked path falls in; null when the pull does not cover it.
     *
     * @param  list<array{type: string, handle: ?string}>  $scopes
     * @return array{type: string, handle: ?string}|null
     */
    private function scopeOf(array $scopes, ?string $site, string $path): ?array
    {
        foreach ($scopes as $scope) {
            if (ContentPaths::inScope($scope['type'], $path, $scope['handle'], $site)) {
                return $scope;
            }
        }

        return null;
    }

    /** @param  array{all: bool, scopes: list<array{handle: string, site: ?string}>}|null  $coverage */
    private static function readable(?array $coverage, string $type, string $path): bool
    {
        $parsed = ContentPaths::parse($type, $path);

        if ($coverage === null || $parsed === null) {
            return false;
        }

        return $coverage['all'] || in_array($parsed, $coverage['scopes'], true);
    }

    /** @param  array{type: string, handle: ?string}  $scope */
    private static function key(array $scope): string
    {
        return $scope['type'].':'.$scope['handle'];
    }

    /** @param  array<string, list<string>>  $plan */
    private function report(array $plan): void
    {
        $labels = [
            'new' => 'New',
            'changed' => 'Changed',
            'deleted' => 'Deleted',
            'skipped' => 'Skipped, changed here since the last pull, or on a first pull since the last commit (--force overwrites)',
            'kept' => 'Kept, deleted on the site but changed here (--force deletes)',
            'unread' => 'Kept, not in the export but in a part of the site you may not read in full',
        ];

        foreach ($labels as $key => $label) {
            if ($plan[$key] === []) {
                continue;
            }

            $this->line("<comment>{$label}:</comment>");

            foreach ($plan[$key] as $path) {
                $this->line("  {$path}");
            }
        }

        if (array_merge(...array_values($plan)) === []) {
            $this->line('Already up to date.');
        }
    }
}
