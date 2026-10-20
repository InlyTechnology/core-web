<?php

namespace Inly\CoreWeb\Snapshots;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\Client\RemoteCoreLogin;
use Inly\CoreWeb\Client\TokenRefused;
use Throwable;

/**
 * Pulls the snapshots from another Core (production, as a rule) into this
 * machine's store and pins them, so a local site renders real data without a
 * local Core. Runs every snapshot's own pull, or its warmer, on the
 * core-web.remote client in place of core-web.client.
 */
class PullSnapshotsCommand extends Command
{
    protected $signature = 'core:pull
        {type? : One snapshot type; every registered type when omitted}
        {--release : Unpin and forget the pulled copies, so the site reads CORE_URL again}
        {--login : Log in to the remote Core in the browser first, even with a stored login}
        {--logout : Revoke and forget this machine\'s login to the remote Core}';

    protected $description = 'Pull the Core snapshots from the remote Core (core-web.remote) and pin them locally';

    public function handle(SnapshotRegistry $registry, SnapshotStore $store): int
    {
        $type = $this->argument('type');

        if ($type !== null && ! $registry->has($type)) {
            $this->error("No snapshot [{$type}] is registered. Known: ".implode(', ', $registry->types()).'.');

            return self::FAILURE;
        }

        $types = $type === null ? $registry->types() : [$type];

        if ($this->option('release')) {
            return $this->release($registry, $store, $types);
        }

        // A pinned copy is never replaced by a warm or a ping: on a server it would stop the site following its Core.
        if (! app()->environment(['local', 'testing'])) {
            $this->error('core:pull pins another Core\'s data on this machine, so it runs only where APP_ENV is local.');

            return self::FAILURE;
        }

        $source = (array) config('core-web.remote');
        $baseUrl = (string) ($source['base_url'] ?? '');
        $clientId = (string) ($source['client_id'] ?? '');

        $tokenKey = (string) ($source['token_cache_key'] ?? 'core_web_remote_access_token');

        if ($this->option('logout')) {
            RemoteCoreLogin::any()?->logout();
            RemoteCoreLogin::forget();
            Cache::forget($tokenKey);
            $this->info('Logged out of the remote Core on this machine.');

            return self::SUCCESS;
        }

        if ($baseUrl === '' || $clientId === '') {
            $this->error('Set remote.base_url and remote.client_id in config/core-web.php: the Core to pull from and the site\'s own client there (README, Pulling production data).');

            return self::FAILURE;
        }

        $secret = (string) ($source['client_secret'] ?? '');

        if ($secret === '' || $this->option('login')) {
            if (! RemoteCoreLogin::secure($baseUrl)) {
                $this->error('remote.base_url must be https (or a .test or localhost Core): the login reads as the site.');

                return self::FAILURE;
            }

            // A token cached for an earlier login or for the secret's client is not this login's.
            Cache::forget($tokenKey);
        }

        $login = $secret === '' || $this->option('login') ? $this->login($baseUrl, $clientId) : null;

        if ($secret === '' && $login === null) {
            return self::FAILURE;
        }

        $host = (string) (parse_url((string) $source['base_url'], PHP_URL_HOST) ?: $source['base_url']);
        $client = config('core-web.client');

        // The pulls build their client from core-web.client when they run (CoreClient::fromConfig()), so the source stands in for it.
        config(['core-web.client' => array_replace((array) $client, [
            'base_url' => $baseUrl,
            'client_id' => $clientId,
            'client_secret' => $login === null ? $secret : '',
            'login_token' => $login?->token() ?? '',
            'ca_bundle' => '',
            'token_cache_key' => $tokenKey,
        ])]);

        try {
            if ($login !== null && ! $this->authenticated()) {
                return self::FAILURE;
            }

            $exit = self::SUCCESS;

            foreach ($types as $name) {
                $exit = max($exit, $this->pull($registry, $store, $name, $host));
            }

            return $exit;
        } finally {
            config(['core-web.client' => $client]);
        }
    }

    /** The stored login, or a new one from the browser: on --login, or when there is none and someone is at the keyboard. */
    private function login(string $baseUrl, string $clientId): ?RemoteCoreLogin
    {
        $stored = $this->option('login') ? null : RemoteCoreLogin::stored($baseUrl, $clientId);

        if ($stored !== null) {
            return $stored;
        }

        if (! $this->option('login') && ! $this->input->isInteractive()) {
            $this->error('No login to the remote Core on this machine. Run `php artisan core:pull --login` and approve it in Core.');

            return null;
        }

        $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl);

        try {
            $login = RemoteCoreLogin::login($baseUrl, $clientId, (string) (gethostname() ?: 'local'), function (string $url, string $code) use ($host): void {
                $this->line("Approve this machine in Core ({$host}) to read as the site:");
                $this->line("  {$url}");
                $this->line("  Code: <options=bold>{$code}</>");
                $this->openBrowser($url);
                $this->line('Waiting for approval…');
            });
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return null;
        }

        $this->info('Logged in'.($login->approvedBy() ? ', approved by '.$login->approvedBy() : '').'.');

        return $login;
    }

    /** A login Core no longer honours (expired, revoked, the approver no longer a developer) is forgotten here too. */
    private function authenticated(): bool
    {
        try {
            CoreClient::fromConfig()->authenticate();

            return true;
        } catch (TokenRefused $e) {
            if (str_contains($e->getMessage(), 'unsupported_grant_type')) {
                $this->error('This Core has no local development logins yet. Update it, or set CORE_REMOTE_CLIENT_SECRET.');

                return false;
            }

            if ($e->status >= 400 && $e->status < 500) {
                RemoteCoreLogin::forget();
                $this->error('Core no longer accepts this machine\'s login (expired or revoked). Run `php artisan core:pull --login`.');

                return false;
            }

            $this->error($e->getMessage());

            return false;
        } catch (Throwable $e) {
            $this->error('Could not reach the remote Core: '.$e->getMessage());

            return false;
        }
    }

    private function openBrowser(string $url): void
    {
        if (! $this->input->isInteractive() || app()->runningUnitTests()) {
            return;
        }

        // Best effort: the link is printed either way.
        if (PHP_OS_FAMILY === 'Windows') {
            @pclose(@popen('start "" '.escapeshellarg($url), 'r'));

            return;
        }

        @exec((PHP_OS_FAMILY === 'Darwin' ? 'open' : 'xdg-open').' '.escapeshellarg($url).' > /dev/null 2>&1 &');
    }

    private function pull(SnapshotRegistry $registry, SnapshotStore $store, string $type, string $host): int
    {
        $snapshot = $registry->get($type);
        $warm = $registry->warmer($type);
        $failure = null;

        try {
            $result = $store->pinning($host, fn () => $warm ? $warm($this) : $store->refresh($snapshot));

            if ($warm && $result !== self::SUCCESS) {
                $failure = "its warmer exited with {$result}";
            }
        } catch (Throwable $e) {
            report($e);
            $failure = $e->getMessage();
        } finally {
            $store->forget($snapshot);
        }

        // A warmer can pin its snapshot and fail afterwards, and core:warm skips a pinned snapshot, so both are said.
        if (! $store->pinnedNow($snapshot)) {
            $this->warn("{$type}: nothing pinned from {$host} (".($failure ?? 'the pull failed or came back too empty to trust').').');

            return self::FAILURE;
        }

        $pinned = $warm ? "{$type}: pinned from {$host}" : "{$type}: {$result} documents pinned from {$host}";

        if ($failure !== null) {
            $this->warn("{$pinned}, but {$failure}.");

            return self::FAILURE;
        }

        $this->info("{$pinned}.");

        return self::SUCCESS;
    }

    /** @param  list<string>  $types */
    private function release(SnapshotRegistry $registry, SnapshotStore $store, array $types): int
    {
        $exit = self::SUCCESS;

        foreach ($types as $type) {
            $snapshot = $registry->get($type);

            if ($store->pinned($snapshot) === null) {
                $this->info("{$type}: not pinned.");
            } elseif ($store->release($snapshot)) {
                $this->info("{$type}: released.");
            } else {
                $this->error("{$type}: busy with another write; still pinned, try again.");
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }
}
