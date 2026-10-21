<?php

namespace Inly\CoreWeb\ContentSync;

/**
 * One status line a long wait rewrites in place, so a pull or a login is never
 * silent. Only on a terminal: piped output and logs get the result lines alone.
 */
trait ReportsProgress
{
    private bool $progressShown = false;

    protected function progress(string $message): void
    {
        if (! $this->output->isDecorated()) {
            return;
        }

        $this->output->write("\r\033[2K<comment>{$message}</comment>");
        $this->progressShown = true;
    }

    /** Clears the status line before a line that stays. */
    protected function endProgress(): void
    {
        if (! $this->progressShown) {
            return;
        }

        $this->output->write("\r\033[2K");
        $this->progressShown = false;
    }
}
