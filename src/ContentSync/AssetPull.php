<?php

namespace Inly\CoreWeb\ContentSync;

use Closure;
use Inly\CoreWeb\Media\MediaDisks;
use Inly\CoreWeb\Media\RemoteIndex;
use Statamic\Contracts\Assets\AssetContainer as Container;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blink;
use Statamic\Facades\Stache;

/**
 * The asset half of a pull: the live site's index of each local container
 * whose disk reads one (MediaDisks::isRemoteIndexed), written to the
 * container's RemoteIndex and nowhere else. Never a file's bytes, and never
 * a write to the live site.
 */
class AssetPull
{
    public const MAX_PAGES = 10000;

    /** A file's `.meta` past this is cut to the keys Statamic itself writes, so one oversized entry cannot swell the index. */
    public const MAX_META_BYTES = 16 * 1024;

    private const META_KEYS = ['data', 'size', 'last_modified', 'width', 'height', 'mime_type', 'duration'];

    public function __construct(private readonly int $maxPages = self::MAX_PAGES) {}

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

        // A disk the app defines itself stays local on purpose, so only core-web's own are worth naming.
        $skipped = $skipped->filter(fn (Container $container) => $handle !== null || MediaDisks::defines((string) $container->diskHandle()));

        return ['pull' => $pull->values()->all(), 'skipped' => $skipped->map(fn (Container $container) => $container->handle())->values()->all()];
    }

    /**
     * Every file the site lists in the container, each path checked before anything is written.
     *
     * @param  (Closure(int): void)|null  $progress  told the number of files listed so far, after each page
     * @return array{url: ?string, files: array<string, array{url: ?string, meta: ?array<string, mixed>}>, dropped: int}|null null when the site has no such container or the user may not view it
     *
     * @throws AssetsNotExported when the site's core-web predates the asset export
     */
    public function fetch(RemoteSite $remote, Container $container, ?Closure $progress = null): ?array
    {
        $handle = $container->handle();
        $files = [];
        $url = null;
        $hosts = [];
        $dropped = 0;
        $cursor = '0';

        for ($page = 0; $cursor !== null; $page++) {
            if ($page >= $this->maxPages) {
                throw new RemoteSiteError("The site kept paging assets past {$this->maxPages} pages.");
            }

            $response = $remote->get('export', ['type' => AssetExport::TYPE, 'handle' => $handle, 'cursor' => $cursor]);
            $error = $response->json('error');

            // Only the export's own answers: a proxy's or a firewall's 403 says nothing about the container.
            if (($response->status() === 404 && $error === 'not_found') || ($response->status() === 403 && $error === 'forbidden')) {
                return null;
            }

            // An older core-web lists the types it exports and refuses `assets` as one it does not know.
            if ($response->status() === 422 && $error === 'invalid_request') {
                throw new AssetsNotExported($remote->url());
            }

            if (! $response->successful() || ! is_array($response->json('data'))) {
                throw new RemoteSiteError("{$remote->url()} answered HTTP {$response->status()} for assets: ".($response->json('message') ?? $error ?? 'no reason given').'.');
            }

            if ($page === 0) {
                $reported = $response->json("containers.{$handle}.url");
                $hosts = RemoteIndex::hosts($remote->url(), is_string($reported) ? $reported : null);
                $url = RemoteIndex::allowedUrl($reported, $hosts);
            }

            foreach ($response->json('data') as $item) {
                $path = is_array($item) ? ($item['path'] ?? null) : null;

                if (! is_string($path) || ($item['container'] ?? null) !== $handle || ! self::safe($path)) {
                    throw new RemoteSiteError('The site sent an asset at ['.(is_string($path) ? $path : '?')."] in [{$handle}], which is no file path inside the container; nothing was written.");
                }

                $fileUrl = RemoteIndex::allowedUrl($item['url'] ?? null, $hosts);

                // A URL off the site's own hosts would have this machine fetch whatever it names.
                if (($item['url'] ?? null) !== null && $fileUrl === null) {
                    $dropped++;

                    continue;
                }

                $files[$path] = [
                    'url' => $fileUrl,
                    'meta' => self::meta($item['meta'] ?? null),
                ];
            }

            if ($progress !== null) {
                $progress(count($files));
            }

            $next = $response->json('next_cursor');
            $cursor = is_scalar($next) && (string) $next !== '' ? (string) $next : null;
        }

        return ['url' => $url, 'files' => $files, 'dropped' => $dropped];
    }

    /**
     * Replaces the container's index, or removes it when the site sent none, and lets Statamic see the change.
     *
     * @param  array{url: ?string, files: array<string, array{url: ?string, meta: ?array<string, mixed>}>}|null  $index
     */
    public function apply(RemoteSite $remote, Container $container, ?array $index): void
    {
        $disk = (string) $container->diskHandle();
        $store = RemoteIndex::forDisk($disk);
        $before = $store->paths($container->handle());

        $store->prune(AssetContainer::all()
            ->filter(fn (Container $other) => $other->diskHandle() === $disk)
            ->map(fn (Container $other) => $other->handle())
            ->values()
            ->all());

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
        // First: clearing the Stache's paths lists the container, which would cache the listing again.
        Stache::store('assets')->store($container->handle())->clear();

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

    /** @return array<string, mixed>|null */
    private static function meta(mixed $meta): ?array
    {
        if (! is_array($meta)) {
            return null;
        }

        if (strlen((string) json_encode($meta)) <= self::MAX_META_BYTES) {
            return $meta;
        }

        $meta = array_intersect_key($meta, array_flip(self::META_KEYS));

        return strlen((string) json_encode($meta)) <= self::MAX_META_BYTES ? $meta : array_diff_key($meta, ['data' => true]);
    }
}
