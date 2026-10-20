<?php

namespace Inly\CoreWeb\Media;

/**
 * How a transforming CDN's URLs are written: which URLs it serves, and the
 * image, video and frame renditions of one. Bound from core-web.media.cdn.scheme.
 */
interface UrlScheme
{
    /** Whether the CDN serves this URL, so transform params mean something on it. */
    public function serves(string $url): bool;

    /**
     * An image rendition; a URL the CDN does not serve, or an SVG, comes back untouched.
     *
     * @param  array<string, scalar|null>  $params  width, height, fit, format, quality, dpr, trim, gravity ("XxY", fractions of the image a crop keeps)
     */
    public function image(string $url, array $params): string;

    /**
     * A video rendition; a URL the CDN does not serve comes back untouched.
     *
     * @param  array<string, scalar|null>  $params  width, height, audio
     */
    public function video(string $url, array $params): string;

    /** A still frame of a video, or null when the CDN cannot make one of this URL. */
    public function frame(string $url, float $seconds, ?int $width = null, ?int $height = null): ?string;
}
