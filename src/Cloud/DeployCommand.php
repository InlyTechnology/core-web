<?php

namespace Inly\CoreWeb\Cloud;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Inly\CoreWeb\CoreWebServiceProvider;

/**
 * Everything a deploy runs once the new code is live, so a host's deploy commands are this
 * one line. It stops at the first step that fails, so the host marks the deploy failed.
 */
class DeployCommand extends Command
{
    protected $signature = 'core-web:deploy';

    protected $description = 'Run the deploy steps: migrations, the stache, the static cache, search indexes, Core snapshots';

    public function handle(): int
    {
        if (CloudDefaults::onCloud() && ! CloudDefaults::contentInDatabase()) {
            $this->error('On Laravel Cloud content and users must be on the eloquent driver: every deploy resets the disk. Install statamic/eloquent-driver and publish its config.');

            return self::FAILURE;
        }

        if (CloudDefaults::onCloud()) {
            $this->warnAboutLocalSearch();
            $this->warnAboutFrequentTasks();
        }

        foreach ($this->steps() as [$command, $arguments, $required]) {
            $this->components->info($command);

            if ($this->call($command, $arguments) === self::SUCCESS) {
                continue;
            }

            if ($required) {
                $this->error("{$command} failed: the deploy stops here.");

                return self::FAILURE;
            }

            $this->components->warn("{$command} failed; the deploy goes on.");
        }

        return self::SUCCESS;
    }

    /** A local index is written to this container's disk, which no web replica reads and the next deploy resets. */
    private function warnAboutLocalSearch(): void
    {
        $local = collect((array) config('statamic.search.indexes', []))
            ->filter(fn ($index): bool => ($index['driver'] ?? null) === 'local')
            ->keys();

        if ($local->isNotEmpty()) {
            $this->components->warn('Search indexes on the local driver stay empty on Cloud: '.$local->implode(', ').'. Use a remote search driver there.');
        }
    }

    /** Cloud's scheduler wakes a hibernating site for each due task, so one due every few minutes keeps it awake for good. */
    private function warnAboutFrequentTasks(): void
    {
        $frequent = collect($this->laravel->make(Schedule::class)->events())
            ->filter(fn (Event $event): bool => preg_match('#^\*(/([0-9]|1[0-4]))?$#', explode(' ', $event->expression)[0]) === 1)
            ->map(fn (Event $event): string => $event->getSummaryForDisplay());

        if ($frequent->isNotEmpty()) {
            $this->components->warn('Scheduled more often than every 15 minutes, which keeps the site from sleeping on Cloud: '.$frequent->implode(', ').'.');
        }
    }

    /** @return list<array{string, array<string, mixed>, bool}> command, arguments, whether a failure fails the deploy */
    private function steps(): array
    {
        $steps = [
            ['migrate', ['--force' => true], true],
            ['statamic:stache:refresh', [], true],
            ['statamic:static:clear', [], true],
            ['statamic:search:update', ['--all' => true], true],
        ];

        // Not required: with Core down the site serves the snapshots it has, and a deploy should not wait for Core.
        if (CoreWebServiceProvider::enabled('snapshots')) {
            $steps[] = ['core:warm', [], false];
        }

        foreach ((array) config('core-web.cloud.deploy', []) as $command) {
            $steps[] = [(string) $command, [], true];
        }

        return $steps;
    }
}
