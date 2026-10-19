<?php

namespace Inly\CoreWeb\Checks;

use Illuminate\Console\Command;

/** Runs the site's content checks and keeps their findings for the CP widget and for sources to read. */
class ContentCheckCommand extends Command
{
    protected $signature = 'content:check {check?* : The checks to run, by key (every one when none)} {--json : Print the findings as JSON}';

    protected $description = 'Find links that will rot, references to deleted content and pages search engines read badly';

    public function handle(ContentChecks $checks): int
    {
        $all = $checks->all();
        $keys = $this->argument('check') ?: array_keys($all);
        $unknown = array_diff($keys, array_keys($all));

        if ($unknown !== []) {
            $this->error('No such check: '.implode(', ', $unknown).'. The checks are: '.implode(', ', array_keys($all)).'.');

            return self::FAILURE;
        }

        $results = array_map(fn (string $key) => $checks->run($all[$key]), $keys);

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            if (isset($result['error'])) {
                $this->error("{$result['check']}: failed: {$result['error']}");

                continue;
            }

            $this->info("{$result['check']}: ".count($result['findings']).' found');

            foreach ($result['findings'] as $finding) {
                $this->line("  {$finding['label']} [{$finding['path']}] {$finding['message']}".($finding['fixable'] ? ' (fixable)' : ''));
            }
        }

        return collect($results)->contains(fn (array $result) => isset($result['error'])) ? self::FAILURE : self::SUCCESS;
    }
}
