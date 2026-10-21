<?php

namespace Inly\CoreWeb\Tests\Unit;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Inly\CoreWeb\ContentSync\ReportsProgress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ReportsProgressTest extends TestCase
{
    private function written(bool $decorated): string
    {
        $buffer = new BufferedOutput(decorated: $decorated);
        $command = new class extends Command
        {
            use ReportsProgress;

            protected $signature = 'progress:test';

            public function report(): void
            {
                $this->progress('Fetching 1…');
                $this->progress('Fetching 2…');
                $this->endProgress();
                $this->line('Done.');
            }
        };

        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
        $command->report();

        return $buffer->fetch();
    }

    public function test_a_terminal_sees_the_line_rewritten_and_cleared_before_the_result(): void
    {
        $written = $this->written(true);

        $this->assertStringContainsString("\r\033[2K", $written);
        $this->assertStringContainsString('Fetching 2…', $written);
        $this->assertStringEndsWith("\r\033[2KDone.\n", $written);
    }

    public function test_piped_output_gets_the_result_alone(): void
    {
        $this->assertSame("Done.\n", $this->written(false));
    }
}
