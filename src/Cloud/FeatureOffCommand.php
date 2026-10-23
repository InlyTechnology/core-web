<?php

namespace Inly\CoreWeb\Cloud;

use Illuminate\Console\Command;

/**
 * Stands in for a feature's command while the feature is off, so a launch's fixed first-boot list (media:sync, content:check, media:check) runs on every Cloud site and says what it skipped.
 */
class FeatureOffCommand extends Command
{
    public function __construct(string $name, private readonly string $feature)
    {
        $this->signature = $name;
        $this->description = "Off: needs core-web's {$feature} feature";
        $this->hidden = true;

        parent::__construct();

        // The real command's arguments and options are accepted and ignored.
        $this->ignoreValidationErrors();
    }

    public function handle(): int
    {
        $this->components->info("Skipped: core-web's {$this->feature} feature is off on this site.");

        return self::SUCCESS;
    }
}
