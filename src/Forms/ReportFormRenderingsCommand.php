<?php

namespace Inly\CoreWeb\Forms;

use Illuminate\Console\Command;
use Throwable;

/** Tells Core which forms the site renders with its own markup, so Core guards their fields; run on every deploy. */
class ReportFormRenderingsCommand extends Command
{
    protected $signature = 'core:forms-report';

    protected $description = 'Report the forms the site renders itself to Core';

    public function handle(FormRenderings $renderings): int
    {
        try {
            $answer = $renderings->report();
        } catch (Throwable $e) {
            $this->error("The report failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        foreach ($answer['accepted'] as $rendering) {
            $this->line("{$rendering['renderer']}: guarded in Core.");
        }

        foreach ($answer['refused'] as $rendering) {
            $this->warn("{$rendering['renderer']}: refused by Core ({$rendering['reason']}). Link the form to this site's app client in Core.");
        }

        return $answer['refused'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
