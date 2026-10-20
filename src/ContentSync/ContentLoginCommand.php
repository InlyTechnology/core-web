<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Signs this machine in to the remote site as the developer's CMS user: the
 * site shows a code, the developer approves it in the CP (signing in there
 * the way the site allows), and the machine is handed a personal token.
 * No shared secret and no env var: the URL is committed config.
 */
class ContentLoginCommand extends Command
{
    protected $signature = 'content:login
        {--no-browser : Print the link without trying to open a browser}';

    protected $description = 'Sign this machine in to the remote site (core-web.content_sync.remote_url) to pull its content';

    public function handle(): int
    {
        $url = RemoteSite::configuredUrl();

        if ($url === '') {
            $this->error('Set core-web.content_sync.remote_url in config/core-web.php: the live site to sync content with.');

            return self::FAILURE;
        }

        try {
            $device = RemoteSite::anonymous($url)->post(RemoteSite::endpoint('device'), ['machine' => self::machine()]);
            RemoteSite::assertUsable($url, $device, RemoteSite::endpoint('device'));
        } catch (RemoteSiteError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ConnectionException $e) {
            $this->error("Could not reach {$url}: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $device->successful() || ! is_string($device->json('device_code'))) {
            $this->error("{$url} did not start a login (HTTP {$device->status()}): ".($device->json('message') ?? 'is content sync switched on there?'));

            return self::FAILURE;
        }

        $link = (string) $device->json('verification_uri_complete');
        $this->line("Approve this machine at <href={$link}>{$link}</>");
        $this->line('and check that it shows the code <info>'.$device->json('user_code').'</info>.');
        $this->openBrowser($link);

        return $this->poll($url, (string) $device->json('device_code'), max(1, (int) $device->json('interval', 5)), max(1, (int) $device->json('expires_in', 600)));
    }

    private function poll(string $url, string $deviceCode, int $interval, int $expiresIn): int
    {
        $waited = 0;

        while ($waited < $expiresIn) {
            Sleep::for($interval)->seconds();
            $waited += $interval;

            try {
                $answer = RemoteSite::anonymous($url)->post(RemoteSite::endpoint('token'), ['device_code' => $deviceCode]);
            } catch (ConnectionException) {
                continue;
            }

            if ($answer->status() === 429) {
                $retry = $answer->header('Retry-After');
                $interval = max($interval + 5, ctype_digit($retry) ? (int) $retry : 0);
                $this->warn("{$url} is rate limiting this machine (HTTP 429); checking every {$interval} seconds now.");

                continue;
            }

            try {
                RemoteSite::assertUsable($url, $answer, RemoteSite::endpoint('token'));
            } catch (RemoteSiteError $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            if ($answer->successful() && is_string($answer->json('token'))) {
                // A machine has one login: the one it replaces stops working on its site too, not only here.
                RemoteSite::stored()?->revoke();
                $site = RemoteSite::store($url, $answer->json('token'), (array) $answer->json('user'), $answer->json('expires_at'));
                $this->info('Signed in to '.$url.' as '.($site->user()['name'] ?: $site->user()['email']).'.');

                return self::SUCCESS;
            }

            switch ($answer->json('error')) {
                case 'authorization_pending':
                    break;
                case 'slow_down':
                    $interval += 5;
                    break;
                case 'access_denied':
                    $this->error('The login was denied in the CP.');

                    return self::FAILURE;
                case 'expired_token':
                    $this->error('The code expired before it was approved. Run php artisan content:login again.');

                    return self::FAILURE;
                default:
                    $this->error("{$url} answered HTTP {$answer->status()} while signing in: ".($answer->json('message') ?? $answer->json('error') ?? 'no reason given').'.');

                    return self::FAILURE;
            }
        }

        $this->error('The code expired before it was approved. Run php artisan content:login again.');

        return self::FAILURE;
    }

    /** Best effort: a terminal that cannot open a browser still has the link printed above. */
    private function openBrowser(string $link): void
    {
        if ($this->option('no-browser') || ! $this->input->isInteractive()) {
            return;
        }

        $opener = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Linux' => 'xdg-open',
            default => null,
        };

        if ($opener === null) {
            return;
        }

        try {
            Process::timeout(10)->run([$opener, $link]);
        } catch (Throwable) {
            // Nothing to do: the link is printed.
        }
    }

    /** What the CP's approval page and tokens list call this machine. */
    private static function machine(): string
    {
        $user = get_current_user();
        $host = gethostname() ?: 'unknown host';

        return $user !== '' ? "{$user}@{$host}" : $host;
    }
}
