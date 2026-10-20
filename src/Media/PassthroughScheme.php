<?php

namespace Inly\CoreWeb\Media;

/** No CDN: every URL renders as it is. */
final class PassthroughScheme implements UrlScheme
{
    public function serves(string $url): bool
    {
        return false;
    }

    public function image(string $url, array $params): string
    {
        return $url;
    }

    public function video(string $url, array $params): string
    {
        return $url;
    }

    public function frame(string $url, float $seconds, ?int $width = null, ?int $height = null): ?string
    {
        return null;
    }
}
