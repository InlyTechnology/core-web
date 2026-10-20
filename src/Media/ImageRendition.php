<?php

namespace Inly\CoreWeb\Media;

use Illuminate\Support\Arr;

/**
 * What <x-core-img> renders for one source: the src, a srcset of CDN widths
 * (or of Glide presets without a CDN), and the inline style carrying the blurred placeholder, the ratio box and
 * the focal point.
 */
final class ImageRendition
{
    private const PLACEHOLDER_WIDTH = 24;

    private const PLACEHOLDER_QUALITY = 30;

    /** @param  array<string, string>  $attributes */
    private function __construct(
        public readonly string $src,
        public readonly ?string $srcset,
        public readonly ?string $sizes,
        public readonly array $attributes,
    ) {}

    /**
     * @param  array{width?: mixed, height?: mixed, quality?: mixed, fit?: mixed, format?: mixed, widths?: mixed, sizes?: mixed, placeholder?: mixed, trim?: mixed, ratio?: mixed, styled?: bool}  $options
     */
    public static function for(MediaSource $source, array $options, ?UrlScheme $scheme = null): self
    {
        $scheme ??= app(UrlScheme::class);
        $aspect = self::aspect($options['ratio'] ?? null);
        $position = $source->objectPosition();
        $sizes = is_string($options['sizes'] ?? null) && $options['sizes'] !== '' ? $options['sizes'] : null;
        $placeholder = null;

        if ($scheme->serves($source->url) && ! $source->isSvg()) {
            $params = self::params($options, $aspect);
            $widths = self::widths($options['widths'] ?? null);
            $box = fn (int $width): array => $aspect
                ? ['width' => $width, 'height' => (int) round($width / $aspect)]
                : ['width' => $width];

            $src = $scheme->image($source->url, $widths ? Arr::except($params, ['width', 'height']) : $params);
            $srcset = $widths ? implode(', ', array_map(
                fn (int $width) => $scheme->image($source->url, array_merge(Arr::except($params, ['width', 'height']), $box($width))).' '.$width.'w',
                $widths,
            )) : null;

            if (($options['placeholder'] ?? config('core-web.media.images.placeholder', 'blur')) === 'blur') {
                $placeholder = self::placeholder($scheme, $source, $params, $aspect, $position);
            }
        } else {
            $src = $source->url;
            $srcset = GlidePresets::srcset($source);
            $sizes ??= $srcset ? '100vw' : null;
        }

        $style = implode(' ', array_filter([
            $placeholder,
            $aspect ? 'aspect-ratio: '.round($aspect, 4).';' : null,
            $position && ! ($options['styled'] ?? false) ? "object-position: {$position};" : null,
        ]));

        return new self($src, $srcset, $sizes, array_filter([
            'data-placeholder' => $placeholder ? 'blur' : null,
            'style' => $style !== '' ? $style : null,
        ]));
    }

    /**
     * The CSS that paints a URL as an image's blurred stand-in (resources/css/core-media.css).
     * Percent-encodes what could close the CSS string or the url() around it.
     */
    public static function placeholderStyle(string $url, string $size = 'cover', ?string $position = null): string
    {
        $safe = strtr($url, ["'" => '%27', '"' => '%22', '\\' => '%5C', '(' => '%28', ')' => '%29', "\n" => '', "\r" => '']);
        $size = $size === 'contain' ? 'contain' : 'cover';

        return "--placeholder: url('{$safe}'); --placeholder-size: {$size};"
            .($position ? " --placeholder-position: {$position};" : '');
    }

    /** "4/3", "16:9" or a number: width over height. */
    public static function aspect(mixed $ratio): ?float
    {
        if (is_numeric($ratio) && (float) $ratio > 0) {
            return (float) $ratio;
        }

        if (is_string($ratio) && preg_match('#^\s*(\d+(?:\.\d+)?)\s*[/:]\s*(\d+(?:\.\d+)?)\s*$#', $ratio, $m) === 1 && (float) $m[2] > 0) {
            return (float) $m[1] / (float) $m[2];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function params(array $options, ?float $aspect): array
    {
        $format = array_key_exists('format', $options) ? $options['format'] : config('core-web.media.images.format', 'auto');

        $params = array_filter([
            'width' => $options['width'] ?? null,
            'height' => $options['height'] ?? null,
            'quality' => $options['quality'] ?? null,
            'fit' => $options['fit'] ?? null,
            'format' => $format,
            'trim' => $options['trim'] ?? null,
        ], fn ($value) => $value !== null && $value !== '' && $value !== false);

        if ($aspect && is_numeric($params['width'] ?? null) && ! isset($params['height'])) {
            $params['height'] = (int) round((float) $params['width'] / $aspect);
        }

        return $params;
    }

    /** @return list<int> */
    private static function widths(mixed $widths): array
    {
        if (is_string($widths)) {
            $widths = explode(',', $widths);
        }

        return is_array($widths)
            ? array_values(array_filter(array_map(fn ($width) => is_numeric($width) ? (int) $width : 0, $widths), fn (int $width) => $width > 0))
            : [];
    }

    /**
     * 24px wide in the picture's own shape (and trim, and fit), so it sits where the image will.
     *
     * @param  array<string, mixed>  $params
     */
    private static function placeholder(UrlScheme $scheme, MediaSource $source, array $params, ?float $aspect, ?string $position): string
    {
        $height = match (true) {
            $aspect !== null => (int) max(1, round(self::PLACEHOLDER_WIDTH / $aspect)),
            is_numeric($params['width'] ?? null) && is_numeric($params['height'] ?? null) && (float) $params['width'] > 0 => (int) max(1, round(self::PLACEHOLDER_WIDTH * $params['height'] / $params['width'])),
            default => null,
        };

        $url = $scheme->image($source->url, array_filter([
            ...Arr::except($params, ['width', 'height', 'quality']),
            'width' => self::PLACEHOLDER_WIDTH,
            'height' => $height,
            'quality' => self::PLACEHOLDER_QUALITY,
        ], fn ($value) => $value !== null));

        return self::placeholderStyle($url, in_array($params['fit'] ?? null, ['contain', 'scale-down'], true) ? 'contain' : 'cover', $position);
    }
}
