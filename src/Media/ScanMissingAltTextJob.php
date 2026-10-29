<?php

namespace Inly\CoreWeb\Media;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Counts a container's images without alt text for the `missing_alt_text` widget; one waiting scan per container. */
class ScanMissingAltTextJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function __construct(public string $container, public string $field = 'alt') {}

    public function uniqueId(): string
    {
        return "{$this->container}:{$this->field}";
    }

    public function handle(): void
    {
        MissingAltText::scan($this->container, $this->field);
    }
}
