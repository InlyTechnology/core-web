<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Statamic\Facades\Site;

/**
 * Pulls the live site's content into this checkout's content/ as the files
 * the Stache writes, signed in as the developer's CMS user. content.lock
 * holds the hash of every file as last pulled, so a file the developer
 * changed since is left alone (and listed) unless --force, and a file the
 * site deleted goes only when it was not changed here either.
 */
class ContentPullCommand extends Command
{
    protected $signature = 'content:pull
        {--only= : Types to pull, comma separated, each optionally narrowed to a handle (entries:pages,globals); every type when omitted}
        {--site= : Only this site\'s content}
        {--force : Overwrite or delete files changed here since the last pull}
        {--dry-run : Show what would change and write nothing}';

    protected $description = 'Pull the remote site\'s content (core-web.content_sync.remote_url) into content/';

    private const MAX_PAGES = 10000;

    public function handle(LocalContent $local): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('content:pull overwrites content/ with another site\'s content, so it runs only where APP_ENV is local.');

            return self::FAILURE;
        }

        try {
            $scopes = $this->scopes();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $site = $this->option('site');

        if (is_string($site) && $site !== '' && Site::get($site) === null) {
            $this->error("No site [{$site}]. Known: ".Site::all()->keys()->implode(', ').'.');

            return self::FAILURE;
        }

        $site = is_string($site) && $site !== '' ? $site : null;

        $remote = $this->remote();

        if ($remote === null) {
            return self::FAILURE;
        }

        try {
            $items = $this->fetch($remote, $scopes, $site);
        } catch (RemoteLoginNeeded) {
            if (! $this->input->isInteractive() || $this->call('content:login') !== self::SUCCESS || ($remote = RemoteSite::fromStorage()) === null) {
                $this->error('The remote site refused this machine\'s login: run php please content:login.');

                return self::FAILURE;
            }

            try {
                $items = $this->fetch($remote, $scopes, $site);
            } catch (RemoteSiteError $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        } catch (RemoteSiteError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            return $this->apply($local, $remote, $scopes, $site, $items);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
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
            return array_map(fn (string $type) => ['type' => $type, 'handle' => null], array_keys(ContentPaths::TYPES));
        }

        return array_values(array_map(function (string $part): array {
            [$type, $handle] = array_pad(explode(':', trim($part), 2), 2, null);
            ContentPaths::assertType($type);

            return ['type' => $type, 'handle' => blank($handle) ? null : $handle];
        }, array_filter(explode(',', $only), fn (string $part) => trim($part) !== '')));
    }

    private function remote(): ?RemoteSite
    {
        if (RemoteSite::configuredUrl() === '') {
            $this->error('Set core-web.content_sync.remote_url in config/core-web.php: the live site to pull content from.');

            return null;
        }

        if ($remote = RemoteSite::fromStorage()) {
            return $remote;
        }

        if (! $this->input->isInteractive()) {
            $this->error('This machine is not signed in to '.RemoteSite::configuredUrl().': run php please content:login first.');

            return null;
        }

        $this->line('This machine is not signed in to '.RemoteSite::configuredUrl().' yet.');

        if ($this->call('content:login') !== self::SUCCESS) {
            return null;
        }

        return RemoteSite::fromStorage();
    }

    /**
     * Every item the site exports in the scopes, by path, each path checked
     * before anything is written: one the type does not lay out there, or one
     * with a way out of content/, stops the pull.
     *
     * @param  list<array{type: string, handle: ?string}>  $scopes
     * @return array<string, array{type: string, hash: string, contents: string}>
     */
    private function fetch(RemoteSite $remote, array $scopes, ?string $site): array
    {
        $items = [];

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

                foreach ($response->json('data') as $item) {
                    $path = is_array($item) ? ($item['path'] ?? null) : null;
                    $contents = is_array($item) ? ($item['contents'] ?? null) : null;

                    if (! is_string($path) || ! is_string($contents) || ! ContentPaths::inScope($scope['type'], $path, $scope['handle'], $site)) {
                        throw new RemoteSiteError("The site sent a {$scope['type']} item at [".(is_string($path) ? $path : '?').'], which is no path of its own inside content/; nothing was written.');
                    }

                    $items[$path] = ['type' => $scope['type'], 'hash' => hash('sha256', $contents), 'contents' => $contents];
                }

                $next = $response->json('next_cursor');
                $cursor = is_scalar($next) && (string) $next !== '' ? (string) $next : null;
            }
        }

        return $items;
    }

    /**
     * @param  list<array{type: string, handle: ?string}>  $scopes
     * @param  array<string, array{type: string, hash: string, contents: string}>  $items
     */
    private function apply(LocalContent $local, RemoteSite $remote, array $scopes, ?string $site, array $items): int
    {
        $lock = $local->lock()['items'];
        $plan = ['new' => [], 'changed' => [], 'deleted' => [], 'skipped' => [], 'kept' => []];
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

            if ($current !== null && $current !== $locked && ! $force) {
                $plan['skipped'][] = $path;

                continue;
            }

            $plan[$current === null ? 'new' : 'changed'][] = $path;
            $writes[$path] = $item['contents'];
            $lock[$path] = $item['hash'];
        }

        foreach (array_keys($lock) as $path) {
            if (isset($items[$path]) || ! $this->covers($scopes, $site, $path)) {
                continue;
            }

            $current = $local->hash($path);

            if ($current === null) {
                unset($lock[$path]);
            } elseif ($current === $lock[$path] || $force) {
                $plan['deleted'][] = $path;
                $deletes[] = $path;
                unset($lock[$path]);
            } else {
                $plan['kept'][] = $path;
            }
        }

        $this->report($plan);

        if ($this->option('dry-run')) {
            $this->line('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        foreach ($writes as $path => $contents) {
            $local->write($path, $contents);
        }

        foreach ($deletes as $path) {
            $local->delete($path);
        }

        $local->writeLock($remote->url(), $lock);

        if ($writes !== [] || $deletes !== []) {
            $local->refreshStache();
        }

        $this->info(sprintf('Pulled from %s: %d new, %d changed, %d deleted.', $remote->url(), count($plan['new']), count($plan['changed']), count($plan['deleted'])));

        return self::SUCCESS;
    }

    /** @param  list<array{type: string, handle: ?string}>  $scopes */
    private function covers(array $scopes, ?string $site, string $path): bool
    {
        foreach ($scopes as $scope) {
            if (ContentPaths::inScope($scope['type'], $path, $scope['handle'], $site)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, list<string>>  $plan */
    private function report(array $plan): void
    {
        $labels = [
            'new' => 'New',
            'changed' => 'Changed',
            'deleted' => 'Deleted',
            'skipped' => 'Skipped, changed here since the last pull (--force overwrites)',
            'kept' => 'Kept, deleted on the site but changed here (--force deletes)',
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
