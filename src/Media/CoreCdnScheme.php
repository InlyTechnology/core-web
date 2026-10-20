<?php

namespace Inly\CoreWeb\Media;

/**
 * core-cdn's query-param URLs (the v1 contract in inly-core's
 * docs/reference/services/core-cdn.md), for any URL under the CDN origin:
 * the site's own bucket and the files Core serves alike. Values are clamped
 * to the ranges the CDN accepts, since it rejects an out-of-range value
 * outright instead of clamping it.
 */
final class CoreCdnScheme implements UrlScheme
{
    public const IMAGE_FITS = ['crop', 'contain', 'cover', 'pad', 'scale-down'];

    public const IMAGE_FORMATS = ['auto', 'webp', 'avif', 'jpeg', 'png'];

    public const IMAGE_DIMENSION = [1, 5000];

    public const QUALITY = [1, 100];

    public const DPR = [1, 3];

    public const VIDEO_DIMENSION = [10, 2000];

    /** @var list<string> */
    private readonly array $origins;

    /** @param  string|list<string>|null  $origins */
    public function __construct(string|array|null $origins = null)
    {
        $origins ??= config('core-web.media.cdn.origins', []);

        $this->origins = array_values(array_filter(
            array_map(fn ($origin) => rtrim((string) $origin, '/'), (array) $origins),
            fn (string $origin) => $origin !== '',
        ));
    }

    public function serves(string $url): bool
    {
        foreach ($this->origins as $origin) {
            if (str_starts_with($url, $origin.'/')) {
                return true;
            }
        }

        return false;
    }

    public function image(string $url, array $params): string
    {
        if (! $this->serves($url) || str_ends_with(strtolower(self::path($url)), '.svg')) {
            return $url;
        }

        return self::merge($url, array_filter([
            'width' => self::clamp($params['width'] ?? null, self::IMAGE_DIMENSION),
            'height' => self::clamp($params['height'] ?? null, self::IMAGE_DIMENSION),
            'fit' => in_array($params['fit'] ?? null, self::IMAGE_FITS, true) ? $params['fit'] : null,
            'trim' => self::trim($params['trim'] ?? null),
            'format' => in_array($params['format'] ?? null, self::IMAGE_FORMATS, true) ? $params['format'] : null,
            'quality' => self::clamp($params['quality'] ?? null, self::QUALITY),
            'dpr' => self::clamp($params['dpr'] ?? null, self::DPR),
        ], fn ($value) => $value !== null));
    }

    public function video(string $url, array $params): string
    {
        if (! $this->serves($url)) {
            return $url;
        }

        return self::merge($url, array_filter([
            'mode' => 'video',
            'width' => self::clamp($params['width'] ?? null, self::VIDEO_DIMENSION),
            'height' => self::clamp($params['height'] ?? null, self::VIDEO_DIMENSION),
            'audio' => ($params['audio'] ?? true) === false ? 'false' : null,
        ], fn ($value) => $value !== null));
    }

    public function frame(string $url, float $seconds, ?int $width = null, ?int $height = null): ?string
    {
        if (! $this->serves($url) || $seconds < 0) {
            return null;
        }

        return self::merge($url, array_filter([
            'mode' => 'frame',
            'time' => rtrim(rtrim(number_format($seconds, 1, '.', ''), '0'), '.') ?: '0',
            'width' => self::clamp($width, self::VIDEO_DIMENSION),
            'height' => self::clamp($height, self::VIDEO_DIMENSION),
            'fit' => $width !== null && $height !== null ? 'cover' : null,
        ], fn ($value) => $value !== null));
    }

    /** The URL without its query string. */
    public static function path(string $url): string
    {
        return explode('?', $url, 2)[0];
    }

    /**
     * The URL with these params set, every other param kept as it was: split by hand, since parse_str() turns
     * dots in a name (Core's `trim.border.color`) into underscores.
     *
     * @param  array<string, scalar>  $params
     */
    private static function merge(string $url, array $params): string
    {
        [$path, $query] = array_pad(explode('?', $url, 2), 2, '');
        $set = array_map(fn ($value) => $value === true ? 'true' : (string) $value, $params);

        $kept = array_filter(
            $query === '' ? [] : explode('&', $query),
            fn (string $pair) => ! array_key_exists(rawurldecode(explode('=', $pair, 2)[0]), $set),
        );

        $query = implode('&', [...$kept, ...array_map(fn ($key, $value) => rawurlencode($key).'='.rawurlencode($value), array_keys($set), $set)]);

        return $path.($query !== '' ? '?'.$query : '');
    }

    /** @param  array{0: int, 1: int}  $range */
    private static function clamp(mixed $value, array $range): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return max($range[0], min($range[1], (int) round((float) $value)));
    }

    private static function trim(mixed $trim): ?string
    {
        if ($trim === true || $trim === 'border') {
            return 'border';
        }

        return is_string($trim) && preg_match('/^\d+;\d+;\d+;\d+$/', $trim) === 1 ? $trim : null;
    }
}
