<?php

namespace Inly\CoreWeb\Media;

use Inly\CoreWeb\Support\Assets;
use Inly\CoreWeb\Support\Values;
use Statamic\Contracts\Assets\Asset;
use Throwable;

/**
 * One picture or video, whatever it arrived as: a Statamic Asset (or an
 * assets field's Value, Collection or query builder), a URL, or a file from
 * Core (`url`/`original_url`, `alt`, `focus`/`focal_point`, `width`,
 * `height`, `mime_type`), so the components treat a site's own assets and
 * Core's media the same way. A file in a disk's layer gets the layer's URL.
 */
final class MediaSource
{
    public const IMAGE = 'image';

    public const VIDEO = 'video';

    public const SVG = 'svg';

    /** @param  array{0: float, 1: float}|null  $focus  x and y in percent */
    public function __construct(
        public readonly string $url,
        public readonly string $kind,
        public readonly string $alt = '',
        public readonly ?array $focus = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?string $mimeType = null,
        public readonly ?Asset $asset = null,
    ) {}

    public static function from(mixed $value): ?self
    {
        $value = Values::first($value);

        return match (true) {
            $value instanceof self => $value,
            $value instanceof Asset => self::fromAsset($value),
            is_string($value) && $value !== '' => new self($value, self::kindOf($value)),
            is_array($value) => self::fromArray($value),
            default => null,
        };
    }

    /** A file's URL as the components render it: for an Asset, the layer's URL when the layer holds the file. For og:image, a feed, anything outside the components. */
    public static function urlOf(mixed $value): ?string
    {
        return self::from($value)?->url;
    }

    public function isVideo(): bool
    {
        return $this->kind === self::VIDEO;
    }

    public function isSvg(): bool
    {
        return $this->kind === self::SVG;
    }

    /** The focal point as CSS object-position, or null. */
    public function objectPosition(): ?string
    {
        return $this->focus ? "{$this->focus[0]}% {$this->focus[1]}%" : null;
    }

    private static function fromAsset(Asset $asset): ?self
    {
        $url = self::guard(fn () => $asset->url());

        if (! is_string($url) || $url === '') {
            return null;
        }

        $kind = match (true) {
            self::guard(fn () => $asset->isSvg()) === true => self::SVG,
            self::guard(fn () => $asset->isVideo()) === true => self::VIDEO,
            default => self::kindOf($asset->path()),
        };

        // Meta is read whole: meta($key) re-measures the file on every call while a value is missing.
        return new self(
            url: MediaDisks::layerUrl($asset) ?? $url,
            kind: $kind,
            alt: (string) Assets::meta($asset, 'alt', ''),
            focus: self::focus(Assets::meta($asset, 'focus')),
            mimeType: $kind === self::VIDEO ? Assets::mimeType($asset) : null,
            asset: $asset,
        );
    }

    /** @param  array<string, mixed>  $file */
    private static function fromArray(array $file): ?self
    {
        // Core's `url` can be a rendition already; transforms go on the original.
        $url = $file['original_url'] ?? $file['url'] ?? $file['src'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        $mime = $file['mime_type'] ?? $file['mimeType'] ?? null;
        $kind = match (true) {
            is_string($mime) && str_starts_with($mime, 'video/') => self::VIDEO,
            $mime === 'image/svg+xml' => self::SVG,
            default => self::kindOf($url),
        };

        return new self(
            url: $url,
            kind: $kind,
            alt: (string) ($file['alt'] ?? $file['alt_text'] ?? $file['altText'] ?? ''),
            focus: self::focus($file['focus'] ?? $file['focal_point'] ?? $file['focalPoint'] ?? null),
            width: self::int($file['width'] ?? null),
            height: self::int($file['height'] ?? null),
            mimeType: is_string($mime) ? $mime : null,
        );
    }

    private static function kindOf(string $pathOrUrl): string
    {
        $extension = strtolower(pathinfo(CoreCdnScheme::path($pathOrUrl), PATHINFO_EXTENSION));

        return match ($extension) {
            'svg' => self::SVG,
            'mp4', 'webm', 'mov', 'm4v', 'ogv' => self::VIDEO,
            default => self::IMAGE,
        };
    }

    /**
     * Statamic's "x-y" string or Core's {x, y}, as percentages clamped to 0-100 (Core's zoom, z, is not used).
     *
     * @return array{0: float, 1: float}|null
     */
    private static function focus(mixed $focus): ?array
    {
        if (is_string($focus) && str_contains($focus, '-')) {
            $focus = array_slice(explode('-', $focus), 0, 2);
        } elseif (is_array($focus) && isset($focus['x'], $focus['y'])) {
            $focus = [$focus['x'], $focus['y']];
        }

        if (! is_array($focus) || count($focus) < 2 || ! is_numeric($focus[0]) || ! is_numeric($focus[1])) {
            return null;
        }

        return [max(0.0, min(100.0, (float) $focus[0])), max(0.0, min(100.0, (float) $focus[1]))];
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function guard(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
