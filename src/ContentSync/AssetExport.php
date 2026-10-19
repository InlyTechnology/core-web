<?php

namespace Inly\CoreWeb\ContentSync;

use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Media\MediaDisks;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Assets\AssetContainer as Container;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\URL;
use Statamic\Facades\YAML;
use Throwable;

/**
 * The site's asset index, a page at a time, for a checkout pulling it: each
 * file's path in its container, its public URL and its Statamic `.meta`
 * data, never its bytes. Only containers the user may view are in it. It
 * only reads: a file whose `.meta` is missing is sent without one rather
 * than measured, which would write it.
 */
class AssetExport
{
    public const TYPE = 'assets';

    /**
     * @return array{data: list<array{type: string, id: string, container: string, path: string, url: ?string, meta: ?array<string, mixed>}>, next_cursor: ?string, containers: array<string, array{url: ?string}>}
     */
    public function page(User $user, ?string $handle, int $offset, int $size): array
    {
        $containers = AssetContainer::all()
            ->filter(fn (Container $container) => ($handle === null || $container->handle() === $handle) && $user->can('view', $container))
            ->sortBy(fn (Container $container) => $container->handle())
            ->values();

        $candidates = $containers
            ->flatMap(fn (Container $container) => $container->files()->sort()->values()->map(fn (string $path) => [$container, $path]))
            ->slice($offset, $size + 1)
            ->values();

        return [
            'data' => $candidates->take($size)->map(fn (array $candidate) => $this->item(...$candidate))->all(),
            'next_cursor' => $candidates->count() > $size ? (string) ($offset + $size) : null,
            'containers' => $containers->mapWithKeys(fn (Container $container) => [$container->handle() => ['url' => self::absolute($container->url())]])->all(),
        ];
    }

    /** Whether the user may view the container; null when there is none by that handle. */
    public function viewable(User $user, string $handle): ?bool
    {
        $container = AssetContainer::find($handle);

        return $container === null ? null : $user->can('view', $container);
    }

    /** @return array{type: string, id: string, container: string, path: string, url: ?string, meta: ?array<string, mixed>} */
    private function item(Container $container, string $path): array
    {
        $asset = $container->makeAsset($path);

        return [
            'type' => self::TYPE,
            'id' => $asset->id(),
            'container' => $container->handle(),
            'path' => $path,
            'url' => $this->url($asset),
            'meta' => $this->meta($container, $asset),
        ];
    }

    /** Where a browser fetches the file: a staging layer's own copy when there is one, else the asset's URL. */
    private function url(Asset $asset): ?string
    {
        $url = CoreWebServiceProvider::enabled('media') ? MediaDisks::layerUrl($asset) : null;

        return self::absolute($url ?? $asset->url());
    }

    /**
     * The meta Statamic keeps for the file: its cache, else its `.meta` file.
     *
     * @return array<string, mixed>|null
     */
    private function meta(Container $container, Asset $asset): ?array
    {
        try {
            $cached = $asset->cacheStore()->get($asset->metaCacheKey());

            if (is_array($cached)) {
                return $cached;
            }

            if (! $container->metaFiles()->contains($asset->metaPath())) {
                return null;
            }

            $meta = YAML::parse((string) $container->disk()->get($asset->metaPath()));

            return is_array($meta) ? $meta : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private static function absolute(?string $url): ?string
    {
        return $url === null || $url === '' ? null : URL::makeAbsolute($url);
    }
}
