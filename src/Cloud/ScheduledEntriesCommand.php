<?php

namespace Inly\CoreWeb\Cloud;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Statamic\Events\EntryScheduleReached;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Statamic's every-minute schedule handling as a window since the last run, so it can run as
 * seldom as a sleeping Cloud site wakes: each entry whose date has passed since then gets its
 * EntryScheduleReached, which clears its static cache and updates its search indexes.
 */
class ScheduledEntriesCommand extends Command
{
    public const LAST_RUN = 'core-web:scheduled-entries:last-run';

    protected $signature = 'core-web:scheduled-entries';

    protected $description = 'Dispatch EntryScheduleReached for entries whose date passed since the last run';

    public function handle(): int
    {
        // The start of this minute, exclusive: an entry dated later in it may not count as published yet.
        $until = now()->startOfMinute();
        $since = $this->since($until);

        $collections = Collection::all()->filter->dated()->map->handle()->values()->all();

        $entries = $collections === [] || $since->gte($until) ? collect() : Entry::query()
            ->whereIn('collection', $collections)
            ->where('date', '>=', $since)
            ->where('date', '<', $until)
            ->get();

        $entries->each(fn ($entry) => EntryScheduleReached::dispatch($entry));

        Cache::forever(self::LAST_RUN, $until->getTimestamp());

        $this->components->info("{$entries->count()} scheduled ".str('entry')->plural($entries->count()).' reached since '.$since->toDateTimeString().'.');

        return self::SUCCESS;
    }

    /** Where the last run stopped, but never further back than the lookback, so a site woken after weeks does not walk every entry since. */
    private function since(CarbonInterface $until): CarbonInterface
    {
        $lookback = max(1, (int) config('core-web.cloud.scheduled_entries.lookback_minutes', 1440));
        $earliest = $until->copy()->subMinutes($lookback);
        $last = Cache::get(self::LAST_RUN);

        if (! is_numeric($last)) {
            return $until->copy()->subMinutes(max(1, (int) config('core-web.cloud.scheduled_entries.first_run_minutes', 15)));
        }

        return max($earliest, now()->setTimestamp((int) $last));
    }
}
