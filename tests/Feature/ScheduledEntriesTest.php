<?php

namespace Inly\CoreWeb\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Inly\CoreWeb\Cloud\CloudDefaults;
use Inly\CoreWeb\Cloud\ScheduledEntriesCommand;
use Inly\CoreWeb\Tests\TestCase;
use Statamic\Events\EntryScheduleReached;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

class ScheduledEntriesTest extends TestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected array $features = ['cloud'];

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('core-web.features.cloud', true);
        $app['config']->set('core-web.cloud.detected', false);
        $app['config']->set('statamic.system.handle_scheduled_entries', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDateTime(2026, 10, 22, 10, 30, 20));

        Collection::make('articles')->dated(true)->save();
        Collection::make('pages')->save();
    }

    private function entry(string $slug, string $date): void
    {
        Entry::make()->collection('articles')->slug($slug)->date(Carbon::parse($date))->data(['title' => $slug])->save();
    }

    /** @return list<string> */
    private function reached(): array
    {
        $slugs = [];
        Event::assertDispatched(EntryScheduleReached::class, function (EntryScheduleReached $event) use (&$slugs): bool {
            $slugs[] = $event->entry->slug();

            return true;
        });
        sort($slugs);

        return $slugs;
    }

    public function test_cloud_turns_statamics_every_minute_job_off(): void
    {
        config(['core-web.cloud.detected' => true, 'statamic.system.handle_scheduled_entries' => true]);

        CloudDefaults::apply($this->app);

        $this->assertFalse(config('statamic.system.handle_scheduled_entries'));
    }

    public function test_it_reaches_every_entry_dated_since_the_last_run(): void
    {
        Cache::forever(ScheduledEntriesCommand::LAST_RUN, now()->setTime(10, 0)->getTimestamp());
        $this->entry('before-the-window', '2026-10-22 09:59');
        $this->entry('first-minute', '2026-10-22 10:00');
        $this->entry('mid-window', '2026-10-22 10:17');
        $this->entry('last-full-minute', '2026-10-22 10:29');
        $this->entry('this-minute', '2026-10-22 10:30');
        $this->entry('tomorrow', '2026-10-23 10:15');
        Entry::make()->collection('pages')->slug('undated-collection')->data(['title' => 'Undated'])->save();
        Event::fake([EntryScheduleReached::class]);

        $this->artisan('core-web:scheduled-entries')->assertSuccessful();

        $this->assertSame(['first-minute', 'last-full-minute', 'mid-window'], $this->reached());
        $this->assertSame(now()->setTime(10, 30)->getTimestamp(), Cache::get(ScheduledEntriesCommand::LAST_RUN));
    }

    public function test_the_next_run_picks_up_where_the_last_stopped(): void
    {
        Cache::forever(ScheduledEntriesCommand::LAST_RUN, now()->setTime(10, 30)->getTimestamp());
        $this->entry('reached-last-run', '2026-10-22 10:29');
        $this->entry('this-minute', '2026-10-22 10:30');
        $this->travelTo(now()->setTime(10, 45, 5));
        Event::fake([EntryScheduleReached::class]);

        $this->artisan('core-web:scheduled-entries')->assertSuccessful();

        $this->assertSame(['this-minute'], $this->reached());
    }

    public function test_a_first_run_looks_one_wake_up_interval_back(): void
    {
        $this->entry('sixteen-minutes-ago', '2026-10-22 10:14');
        $this->entry('ten-minutes-ago', '2026-10-22 10:20');
        Event::fake([EntryScheduleReached::class]);

        $this->artisan('core-web:scheduled-entries')->assertSuccessful();

        $this->assertSame(['ten-minutes-ago'], $this->reached());
    }

    public function test_after_a_long_gap_it_looks_back_no_further_than_the_lookback(): void
    {
        Cache::forever(ScheduledEntriesCommand::LAST_RUN, now()->subDays(30)->getTimestamp());
        $this->entry('two-days-ago', '2026-10-20 10:00');
        $this->entry('an-hour-ago', '2026-10-22 09:30');
        Event::fake([EntryScheduleReached::class]);

        $this->artisan('core-web:scheduled-entries')->assertSuccessful();

        $this->assertSame(['an-hour-ago'], $this->reached());
    }

    public function test_it_is_scheduled_only_while_statamics_own_job_is_off(): void
    {
        $scheduled = fn (): bool => collect($this->app->make(Schedule::class)->events())
            ->contains(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'core-web:scheduled-entries') && $event->expression === '*/15 * * * *');

        $this->assertTrue($scheduled());

        $this->app->forgetInstance(Schedule::class);
        config(['statamic.system.handle_scheduled_entries' => true]);

        $this->assertFalse($scheduled());
    }
}
