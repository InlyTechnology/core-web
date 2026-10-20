<?php

namespace Inly\CoreWeb\Support;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Inly\CoreWeb\CoreWebServiceProvider;

/**
 * What the switched-on features need from this environment that a site's own setup can get wrong: the Core
 * client, a real queue, the scheduler. Runs offline, so a deploy or a launch check can call it anywhere.
 */
class DoctorCommand extends Command
{
    /** Features that call Core with the site's own client. */
    public const CLIENT_FEATURES = ['snapshots', 'chat', 'content', 'live', 'forms', 'cms_sign_in'];

    /** Features whose work a ping or Core's notice queues: on a sync queue it runs inside Core's request. */
    public const QUEUE_FEATURES = ['snapshots', 'cms_sign_in'];

    /** The commands each feature schedules, by the config key that sets when. */
    public const SCHEDULED = [
        'snapshots' => ['core:warm', 'snapshots.warm_schedule'],
        'checks' => ['content:check', 'checks.schedule'],
        'cms_sign_in' => ['core:reconcile-cms-users', 'cms_sign_in.reconcile_schedule'],
    ];

    protected $signature = 'core-web:doctor';

    protected $description = 'Check what the switched-on core-web features need from this environment';

    private bool $anyFailed = false;

    public function handle(Schedule $schedule): int
    {
        $features = array_keys(array_filter(
            (array) config('core-web.features'),
            fn (mixed $on, string $feature): bool => CoreWebServiceProvider::enabled($feature),
            ARRAY_FILTER_USE_BOTH,
        ));
        $this->line('Features on: '.implode(', ', $features));

        $this->checkClient($features);
        $this->checkQueue($features);
        $this->checkSchedule($features, $schedule);
        $this->checkPingSecret($features);
        $this->checkCmsAccounts($features);

        if (in_array('media', $features, true)) {
            $this->line('media: run `php artisan media:check` for the disks and the bucket.');
        }

        return $this->anyFailed ? self::FAILURE : self::SUCCESS;
    }

    /** @param  list<string>  $features */
    private function checkClient(array $features): void
    {
        $needing = array_values(array_intersect($features, self::CLIENT_FEATURES));

        if ($needing === []) {
            return;
        }

        $missing = array_keys(array_filter([
            'CORE_URL' => config('core-web.client.base_url'),
            'CORE_CLIENT_ID' => config('core-web.client.client_id'),
            'CORE_CLIENT_SECRET' => config('core-web.client.client_secret'),
        ], fn (mixed $value): bool => blank($value)));

        if ($missing === []) {
            $this->ok('Core client: '.config('core-web.client.base_url'));

            return;
        }

        // A local machine without its own Core reads the remote one (core:pull, CMS sign-in through the remote Core).
        if ($this->laravel->environment('local') && blank(config('core-web.client.base_url'))) {
            $remote = ! blank(config('core-web.remote.base_url')) && ! blank(config('core-web.remote.client_secret'));
            $remote
                ? $this->ok('Core client: none here, the remote Core ('.config('core-web.remote.base_url').') stands in')
                : $this->warning('Core client: none here and no remote Core; set CORE_REMOTE_CLIENT_SECRET to pull and sign in through it');

            return;
        }

        $this->failure('Core client: '.implode(', ', $missing).' not set, which '.implode(', ', $needing).' need');
    }

    /** @param  list<string>  $features */
    private function checkQueue(array $features): void
    {
        $needing = array_values(array_intersect($features, self::QUEUE_FEATURES));

        if ($needing === [] || $this->laravel->environment(['local', 'testing'])) {
            return;
        }

        if (config('queue.default') === 'sync') {
            $this->failure('Queue: QUEUE_CONNECTION is sync, so '.implode(', ', $needing).' run their jobs inside Core\'s request and a bulk ping is cut off; use a real queue with a worker');

            return;
        }

        $this->ok('Queue: '.config('queue.default').' (needs a running worker)');
    }

    /** @param  list<string>  $features */
    private function checkSchedule(array $features, Schedule $schedule): void
    {
        $scheduled = collect($schedule->events())
            ->map(fn (Event $event): string => (string) $event->command)
            ->all();

        foreach (self::SCHEDULED as $feature => [$command, $key]) {
            if (! in_array($feature, $features, true)) {
                continue;
            }

            $found = collect($scheduled)->contains(fn (string $line): bool => str_contains($line, $command));
            $found
                ? $this->ok("Schedule: {$command} (needs `schedule:run` every minute on every server)")
                : $this->warning("Schedule: {$command} is not scheduled (core-web.{$key} is null); schedule it in the site");
        }
    }

    /** @param  list<string>  $features */
    private function checkPingSecret(array $features): void
    {
        if (in_array('snapshots', $features, true) && ! blank(config('core-web.ping.secret'))) {
            $this->warning('Pings: WEBSITE_SYNC_SECRET is set; blank it once Core seals every ping with its own key');
        }
    }

    /** @param  list<string>  $features */
    private function checkCmsAccounts(array $features): void
    {
        if (! in_array('cms_sign_in', $features, true) || ! config('statamic.git.enabled')) {
            return;
        }

        $users = rtrim(str_replace('\\', '/', (string) config('statamic.stache.stores.users.directory', base_path('users'))), '/');
        $committed = collect((array) config('statamic.git.paths'))
            ->map(fn (mixed $path): string => rtrim(str_replace('\\', '/', (string) $path), '/'))
            ->contains(fn (string $path): bool => $path === $users || str_starts_with($users.'/', $path.'/'));

        $committed
            ? $this->failure('CMS accounts: statamic.git.paths commits the users folder; accounts live on the server that created them')
            : $this->ok('CMS accounts: not committed by Statamic\'s git integration');
    }

    private function ok(string $message): void
    {
        $this->line("<info>ok</info>   {$message}");
    }

    private function warning(string $message): void
    {
        $this->line("<comment>warn</comment> {$message}");
    }

    private function failure(string $message): void
    {
        $this->anyFailed = true;
        $this->line("<error>fail</error> {$message}");
    }
}
