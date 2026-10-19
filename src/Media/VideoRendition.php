<?php

namespace Inly\CoreWeb\Media;

/**
 * What <x-core-video> renders for one source: CDN versions sized for wide and
 * small screens (silent when the video plays muted), or the raw file when the
 * CDN does not serve it, and a poster: the one given, or a CDN frame.
 */
final class VideoRendition
{
    public const SMALL_SCREEN = '(max-width: 767px)';

    /** @param  list<array{src: string, media: ?string, type: ?string}>  $sources */
    private function __construct(
        public readonly array $sources,
        public readonly ?string $poster,
    ) {}

    /**
     * @param  array{width?: mixed, small_width?: mixed, muted?: bool, poster?: mixed, poster_time?: mixed}  $options
     */
    public static function for(MediaSource $source, array $options, ?UrlScheme $scheme = null): self
    {
        $scheme ??= app(UrlScheme::class);
        $width = self::int($options['width'] ?? config('core-web.media.videos.width', 1280));
        $smallWidth = self::int($options['small_width'] ?? config('core-web.media.videos.small_width', 720));
        $audio = ! ($options['muted'] ?? true);

        if (! $scheme->serves($source->url)) {
            return new self(
                [['src' => $source->url, 'media' => null, 'type' => $source->mimeType]],
                self::poster($options['poster'] ?? null, $scheme, $width),
            );
        }

        // The CDN serves mp4 whatever the source, so its versions carry that type, not the source's.
        $sources = [];

        if ($smallWidth !== null && ($width === null || $smallWidth < $width)) {
            $sources[] = ['src' => $scheme->video($source->url, ['width' => $smallWidth, 'audio' => $audio]), 'media' => self::SMALL_SCREEN, 'type' => 'video/mp4'];
        }

        $sources[] = ['src' => $scheme->video($source->url, ['width' => $width, 'audio' => $audio]), 'media' => null, 'type' => 'video/mp4'];

        $time = array_key_exists('poster_time', $options) ? $options['poster_time'] : config('core-web.media.videos.poster_time', 0);

        return new self(
            $sources,
            self::poster($options['poster'] ?? null, $scheme, $width)
                ?? (is_numeric($time) ? $scheme->frame($source->url, (float) $time, $width) : null),
        );
    }

    private static function poster(mixed $poster, UrlScheme $scheme, ?int $width): ?string
    {
        $poster = MediaSource::from($poster);

        if ($poster === null || $poster->isVideo()) {
            return null;
        }

        return $scheme->image($poster->url, array_filter([
            'width' => $width,
            'format' => config('core-web.media.images.format', 'auto'),
        ]));
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
