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
 * file's path in its container, its public URL and its stored meta (the
 * `.meta` file, or the database row on the Eloquent driver), never its
 * bytes. Only containers the user may view are in it. It only reads: a file
 * without stored meta is sent without it rather than measured, which would
 * write it.
 */
class AssetExport
{
    public const TYPE = 'assets';

    /** @var array<string, array<string, int>> */
    private array $metaFiles = [];

    /**
     * @return array{data: list<array{type: string, id: string, container: string, path: string, url: ?string, meta: ?array<string, mixed>}>, next_cursor: ?string, containers: array<string, array{url: ?string}>}
     */
    public function page(User $user, ?string $handle, int $offset, int $size): array
    {
        $this->metaFiles = [];

        $containers = AssetContainer::all()
            ->filter(fn (Container $container) => ($handle === null || $container->handle() === $handle) && $user->can('view', $container))
            ->sortBy(fn (Container $container) => $container->handle())
            ->values();

        // One asset past the page says whether another page follows.
        $assets = [];
        $skip = $offset;

        foreach ($containers as $container) {
            $count = $container->queryAssets()->count();

            if ($skip >= $count) {
                $skip -= $count;

                continue;
            }

            foreach ($container->queryAssets()->orderBy('path')->offset($skip)->limit($size + 1 - count($assets))->get() as $asset) {
                $assets[] = [$container, $asset];
            }

            $skip = 0;

            if (count($assets) > $size) {
                break;
            }
        }

        $page = array_map(fn (array $pair) => $this->item(...$pair), array_slice($assets, 0, $size));

        return [
            'data' => $page,
            'next_cursor' => count($assets) > $size ? (string) ($offset + $size) : null,
            'containers' => $containers->mapWithKeys(fn (Container $container) => [$container->handle() => ['url' => self::absolute($container->url())]])->all(),
        ];
    }

    /** Whether the user may view the container; null when there is none by that handle. */
    public function viewable(User $user, string $handle): ?bool
    {
        $container = AssetContainer::find($handle);

        return $container === null ? null : $user->can('view', $container);
    }

    /**
     * @return array{type: string, id: string, container: string, path: string, url: ?string, meta: ?array<string, mixed>}
     */
    private function item(Container $container, Asset $asset): array
    {
        return [
            'type' => self::TYPE,
            'id' => $asset->id(),
            'container' => $container->handle(),
            'path' => $asset->path(),
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
     * The meta Statamic keeps for the file, read where it is stored: the
     * asset's own (a database row on the Eloquent driver), its cache, or its
     * `.meta` file. Never `meta()`, which measures and writes a file it lacks.
     *
     * @return array<string, mixed>|null
     */
    private function meta(Container $container, Asset $asset): ?array
    {
        try {
            $stored = method_exists($asset, 'getRawMeta') ? $asset->getRawMeta() : null;

            if (is_array($stored) && $stored !== []) {
                return $stored;
            }

            $cached = $asset->cacheStore()->get($asset->metaCacheKey());

            if (is_array($cached)) {
                return $cached;
            }

            // On the Eloquent driver metaPath() is the file itself, which must not be read.
            if ($asset->metaPath() === $asset->path()) {
                return null;
            }

            // The container's `.meta` files as a set, listed once a page and only when a file needs it.
            $this->metaFiles[$container->handle()] ??= array_flip($container->metaFiles()->all());

            if (! isset($this->metaFiles[$container->handle()][$asset->metaPath()])) {
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
