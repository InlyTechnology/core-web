<?php

namespace Inly\CoreWeb\Media;

/**
 * What <x-core-video> renders for one source: CDN versions sized for wide and
 * small screens (silent when the video plays muted), or the raw file when the
 * CDN does not serve it, a poster (the one given, or a CDN frame), and a 24px
 * version of that poster painted blurred until the poster or the video arrives.
 */
final class VideoRendition
{
    public const SMALL_SCREEN = '(max-width: 767px)';

    private const PLACEHOLDER_WIDTH = 24;

    private const PLACEHOLDER_QUALITY = 30;

    /**
     * @param  list<array{src: string, media: ?string, type: ?string}>  $sources
     * @param  array<string, string>  $attributes
     */
    private function __construct(
        public readonly array $sources,
        public readonly ?string $poster,
        public readonly array $attributes = [],
    ) {}

    /**
     * @param  array{width?: mixed, small_width?: mixed, muted?: bool, poster?: mixed, poster_time?: mixed, placeholder?: mixed}  $options
     */
    public static function for(MediaSource $source, array $options, ?UrlScheme $scheme = null): self
    {
        $scheme ??= app(UrlScheme::class);
        $width = self::int($options['width'] ?? config('core-web.media.videos.width', 1280));
        $smallWidth = self::int($options['small_width'] ?? config('core-web.media.videos.small_width', 720));
        $audio = ! ($options['muted'] ?? true);

        $blur = ($options['placeholder'] ?? config('core-web.media.images.placeholder', 'blur')) === 'blur';
        $poster = MediaSource::from($options['poster'] ?? null);
        $poster = $poster !== null && ! $poster->isVideo() ? $poster : null;

        if (! $scheme->serves($source->url)) {
            return new self(
                [['src' => $source->url, 'media' => null, 'type' => $source->mimeType]],
                self::poster($poster, $scheme, $width),
                $blur && $poster !== null ? self::placeholder($scheme->image($poster->url, self::tiny()), $poster, $scheme) : [],
            );
        }

        // The CDN serves mp4 whatever the source, so its versions carry that type, not the source's.
        $sources = [];

        if ($smallWidth !== null && ($width === null || $smallWidth < $width)) {
            $sources[] = ['src' => $scheme->video($source->url, ['width' => $smallWidth, 'audio' => $audio]), 'media' => self::SMALL_SCREEN, 'type' => 'video/mp4'];
        }

        $sources[] = ['src' => $scheme->video($source->url, ['width' => $width, 'audio' => $audio]), 'media' => null, 'type' => 'video/mp4'];

        $time = array_key_exists('poster_time', $options) ? $options['poster_time'] : config('core-web.media.videos.poster_time', 0);

        $frame = $poster === null && is_numeric($time);

        return new self(
            $sources,
            $frame ? $scheme->frame($source->url, (float) $time, $width) : self::poster($poster, $scheme, $width),
            match (true) {
                ! $blur => [],
                $frame => self::placeholder($scheme->frame($source->url, (float) $time, self::PLACEHOLDER_WIDTH), null, $scheme),
                $poster !== null => self::placeholder($scheme->image($poster->url, self::tiny()), $poster, $scheme),
                default => [],
            },
        );
    }

    private static function poster(?MediaSource $poster, UrlScheme $scheme, ?int $width): ?string
    {
        if ($poster === null) {
            return null;
        }

        return $scheme->image($poster->url, array_filter([
            'width' => $width,
            'format' => config('core-web.media.images.format', 'auto'),
        ]));
    }

    /** @return array<string, mixed> */
    private static function tiny(): array
    {
        return ['width' => self::PLACEHOLDER_WIDTH, 'quality' => self::PLACEHOLDER_QUALITY, 'format' => config('core-web.media.images.format', 'auto')];
    }

    /**
     * Only a rendition the CDN made: a poster it does not serve would be the full image twice.
     *
     * @return array<string, string>
     */
    private static function placeholder(?string $url, ?MediaSource $poster, UrlScheme $scheme): array
    {
        if ($url === null || ($poster !== null && (! $scheme->serves($poster->url) || $poster->isSvg()))) {
            return [];
        }

        return [
            'data-placeholder' => 'blur',
            'style' => ImageRendition::placeholderStyle($url, 'cover', $poster?->objectPosition()),
        ];
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
