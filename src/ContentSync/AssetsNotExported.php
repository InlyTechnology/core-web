<?php

namespace Inly\CoreWeb\ContentSync;

use RuntimeException;

/** The live site's core-web predates the asset export, so a pull leaves the asset index as it was. */
final class AssetsNotExported extends RuntimeException
{
    public function __construct(public readonly string $url)
    {
        parent::__construct("{$url} does not export assets (its core-web predates the asset index).");
    }
}
