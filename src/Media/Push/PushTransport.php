<?php

namespace Inly\CoreWeb\Media\Push;

/** How AssetPusher reaches the remote site's held-file endpoints. */
interface PushTransport
{
    /**
     * @param  list<array{container: string, path: string, sha256: string, size: int}>  $files
     * @return list<array{status: string, path: string, reason?: string}> one answer per file, in order
     */
    public function check(array $files): array;

    /**
     * @param  resource  $stream
     * @return array{status: string, path: string, reason?: string}
     */
    public function upload(string $container, string $path, string $sha256, $stream): array;
}
