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

    /** A local index is a file each container builds on its first search, which a save updates on one container only. */
    private function warnAboutLocalSearch(): void
    {
        // Statamic's own `cp` index is left out: every site has one, and it serves CP search only.
        $local = array_filter(CloudDefaults::localSearchIndexes(config()), fn (string $index): bool => config()->has("statamic.search.indexes.{$index}"));

        if ($local !== []) {
            $this->components->warn('Search indexes on the local driver are a file on each container on Cloud: '.implode(', ', $local).'. Each container builds its own on its first search, and a save reaches one container\'s copy only, so the others show it after their next restart. Use a remote search driver where edits must show at once, and keep search result pages out of the static cache.');
        }
    }

    /**
     * On Cloud a local index is built by the container that reads it (CloudDefaults), so the deploy builds only those that
     * outlive it: built here, a local one would be written to a disk no web replica reads.
     *
     * @return list<array{string, array<string, mixed>, bool}>
     */
    private function searchSteps(): array
    {
        if (! CloudDefaults::onCloud()) {
            return [['statamic:search:update', ['--all' => true], true]];
        }

        return array_map(
            fn (string $index): array => ['statamic:search:update', ['index' => $index], true],
            ImportContentCommand::lastingSearchIndexes(),
        );
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
            // Statamic fails the command when there is no static cache to clear (a local run, a site that turned it off).
            ...(filled(config('statamic.static_caching.strategy')) ? [['statamic:static:clear', [], true]] : []),
            ...$this->searchSteps(),
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
