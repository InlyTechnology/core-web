<?php

namespace Inly\CoreWeb\Media;

use League\Flysystem\FilesystemException;
use RuntimeException;

/**
 * A layered disk refusing to change a file only the bucket holds. Not one of Flysystem's Unable* exceptions on
 * purpose: Laravel swallows those under `throw => false`, and Statamic would then delete or rename the asset in
 * its content anyway, leaving entries pointing at a file production still has under its old name.
 */
final class ProductionFileLocked extends RuntimeException implements FilesystemException
{
    public static function at(string $path, string $action): self
    {
        return new self("[{$path}] is production's file: outside production it cannot be {$action}, only files added here can.");
    }
}
