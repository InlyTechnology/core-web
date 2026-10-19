<?php

namespace Inly\CoreWeb\ContentSync;

use Inly\CoreWeb\Media\MediaDisks;
use Inly\CoreWeb\Media\RemoteIndex;
use Statamic\Contracts\Assets\AssetContainer as Container;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blink;

/**
 * The asset half of a pull: the live site's index of each local container
 * whose disk reads one (MediaDisks::isRemoteIndexed), written to the
 * container's RemoteIndex and nowhere else. Never a file's bytes, and never
 * a write to the live site.
 */
class AssetPull
{
    private const MAX_PAGES = 10000;

    /**
     * The local containers an asset pull fills, narrowed to a handle, and those it cannot (their disk reads no pulled index).
     *
     * @return array{pull: list<Container>, skipped: list<string>}
     */
    public function containers(?string $handle): array
    {
        $containers = AssetContainer::all()
            ->filter(fn (Container $container) => $handle === null || $container->handle() === $handle)
            ->sortBy(fn (Container $container) => $container->handle());

        if ($handle !== null && $containers->isEmpty()) {
            throw new RemoteSiteError("No asset container [{$handle}] here: its blueprint and container config come with the code, not the pull.");
        }

        [$pull, $skipped] = $containers->partition(fn (Container $container) => MediaDisks::isRemoteIndexed((string) $container->diskHandle()));

        return ['pull' => $pull->values()->all(), 'skipped' => $skipped->map(fn (Container $container) => $container->handle())->values()->all()];
    }

    /**
     * Every file the site lists in the container, each path checked before anything is written.
     *
     * @return array{url: ?string, files: array<string, array{url: ?string, meta: ?array<string, mixed>}>}|null null when the site has no such container or the user may not view it
     */
    public function fetch(RemoteSite $remote, Container $container): ?array
    {
        $handle = $container->handle();
        $files = [];
        $url = null;
        $cursor = '0';

        for ($page = 0; $cursor !== null; $page++) {
            if ($page >= self::MAX_PAGES) {
                throw new RemoteSiteError('The site kept paging assets past '.self::MAX_PAGES.' pages.');
            }

            $response = $remote->get('export', ['type' => AssetExport::TYPE, 'handle' => $handle, 'cursor' => $cursor]);

            if (in_array($response->status(), [403, 404], true)) {
                return null;
            }

            if (! $response->successful() || ! is_array($response->json('data'))) {
                throw new RemoteSiteError("{$remote->url()} answered HTTP {$response->status()} for assets: ".($response->json('message') ?? $response->json('error') ?? 'no reason given').'.');
            }

            $url ??= self::url($response->json("containers.{$handle}.url"));

            foreach ($response->json('data') as $item) {
                $path = is_array($item) ? ($item['path'] ?? null) : null;

                if (! is_string($path) || ($item['container'] ?? null) !== $handle || ! self::safe($path)) {
                    throw new RemoteSiteError('The site sent an asset at ['.(is_string($path) ? $path : '?')."] in [{$handle}], which is no file path inside the container; nothing was written.");
                }

                $files[$path] = [
                    'url' => self::url($item['url'] ?? null),
                    'meta' => is_array($item['meta'] ?? null) ? $item['meta'] : null,
                ];
            }

            $next = $response->json('next_cursor');
            $cursor = is_scalar($next) && (string) $next !== '' ? (string) $next : null;
        }

        return ['url' => $url, 'files' => $files];
    }

    /**
     * Replaces the container's index, or removes it when the site sent none, and lets Statamic see the change.
     *
     * @param  array{url: ?string, files: array<string, array{url: ?string, meta: ?array<string, mixed>}>}|null  $index
     */
    public function apply(RemoteSite $remote, Container $container, ?array $index): void
    {
        $store = RemoteIndex::forDisk((string) $container->diskHandle());
        $before = $store->paths($container->handle());

        if ($index === null) {
            $store->forget($container->handle());
        } else {
            $store->put($container->handle(), $remote->url(), $index['url'], $index['files']);
        }

        $this->forgetCaches($container, array_unique([...$before, ...array_keys($index['files'] ?? [])]));
    }

    /**
     * Statamic keeps a container's listing and each asset's meta in the cache, which knows nothing of an index replaced behind its back.
     *
     * @param  list<string>  $paths
     */
    private function forgetCaches(Container $container, array $paths): void
    {
        // AssetContainerContents' own key: it offers no public way to drop its listing.
        $container->contents()->cacheStore()->forget('asset-list-contents-'.$container->handle());
        Blink::forget('asset-listing-cache-'.$container->handle());

        foreach ($paths as $path) {
            $asset = $container->makeAsset($path);
            $asset->cacheStore()->forget($asset->metaCacheKey());
        }
    }

    /** A file path with no way out of the container, and none into Statamic's own `.meta` folders. */
    private static function safe(string $path): bool
    {
        return ContentPaths::safe($path) && ! in_array('.meta', explode('/', $path), true);
    }

    private static function url(mixed $url): ?string
    {
        return is_string($url) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }
}
