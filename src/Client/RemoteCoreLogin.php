<?php

namespace Inly\CoreWeb\Client;

use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * A developer's login to the remote Core for `core:pull`: a browser approval
 * in Core (device flow) gives a login token that reads as the site's own
 * client, kept on this machine only (storage/core-web/remote-core.json).
 * Core trades it for an hour-long access token on each pull.
 */
final class RemoteCoreLogin
{
    public const GRANT = 'urn:inly:core:local-dev';

    /**
     * @param  array{base_url: string, client_id: string, login_token: string, expires_at: ?string, approved_by: ?string}  $login
     */
    private function __construct(private array $login) {}

    /** The stored login for this Core and client, or null: none, another Core's, or past its expiry. */
    public static function stored(string $baseUrl, string $clientId): ?self
    {
        $path = self::path();

        if ($baseUrl === '' || $clientId === '' || ! is_file($path)) {
            return null;
        }

        $login = json_decode((string) file_get_contents($path), true);

        if (! is_array($login)
            || rtrim((string) ($login['base_url'] ?? ''), '/') !== rtrim($baseUrl, '/')
            || (string) ($login['client_id'] ?? '') !== $clientId
            || blank($login['login_token'] ?? null)) {
            return null;
        }

        $expires = $login['expires_at'] ?? null;

        // Core slides the expiry on every use; this copy only says when it was last known.
        if (is_string($expires) && strtotime($expires) !== false && strtotime($expires) < now()->getTimestamp()) {
            return null;
        }

        return new self([
            'base_url' => rtrim($baseUrl, '/'),
            'client_id' => $clientId,
            'login_token' => (string) $login['login_token'],
            'expires_at' => is_string($expires) ? $expires : null,
            'approved_by' => isset($login['approved_by']) ? (string) $login['approved_by'] : null,
        ]);
    }

    /**
     * Runs the device flow: asks Core for a code, hands the link to `$show`,
     * and polls until the developer approves or denies it in Core, or it expires.
     *
     * @param  Closure(string $url, string $code): void  $show
     */
    public static function login(string $baseUrl, string $clientId, string $machine, Closure $show): self
    {
        $baseUrl = rtrim($baseUrl, '/');

        if (! CoreClient::secureUrl($baseUrl)) {
            throw new RuntimeException('core-web logs in to the remote Core over https only; remote.base_url is not.');
        }

        $start = self::post($baseUrl.'/api/local-dev/logins', ['client_id' => $clientId, 'machine' => $machine]);

        if (! $start->successful() || blank($start->json('device_code'))) {
            throw new RuntimeException(self::refusal($start, 'Core refused to start a login for this site\'s client'));
        }

        $show((string) ($start->json('verification_uri_complete') ?: $start->json('verification_uri')), (string) $start->json('user_code'));

        $interval = max(1, (int) $start->json('interval', 5));
        $deadline = now()->getTimestamp() + max(60, (int) $start->json('expires_in', 600));

        while (now()->getTimestamp() < $deadline) {
            Sleep::for($interval)->seconds();

            $poll = self::post($baseUrl.'/api/local-dev/logins/token', [
                'client_id' => $clientId,
                'device_code' => (string) $start->json('device_code'),
            ]);

            if ($poll->successful() && filled($poll->json('login_token'))) {
                $login = new self([
                    'base_url' => $baseUrl,
                    'client_id' => $clientId,
                    'login_token' => (string) $poll->json('login_token'),
                    'expires_at' => $poll->json('expires_at'),
                    'approved_by' => $poll->json('approved_by'),
                ]);
                $login->save();

                return $login;
            }

            $error = (string) $poll->json('error');

            if ($error === 'slow_down') {
                $interval += 5;
            } elseif ($error !== 'authorization_pending') {
                throw new RuntimeException(match ($error) {
                    'access_denied' => 'The login was denied in Core.',
                    'expired_token' => 'The login code expired before it was approved. Run it again.',
                    default => self::refusal($poll, 'Core refused the login'),
                });
            }
        }

        throw new RuntimeException('The login code expired before it was approved. Run it again.');
    }

    public function baseUrl(): string
    {
        return $this->login['base_url'];
    }

    public function clientId(): string
    {
        return $this->login['client_id'];
    }

    public function token(): string
    {
        return $this->login['login_token'];
    }

    public function approvedBy(): ?string
    {
        return $this->login['approved_by'];
    }

    /** Revokes the login in Core (best effort: a Core that is down still loses it from this machine) and forgets it. */
    public function logout(): void
    {
        try {
            self::post($this->baseUrl().'/api/local-dev/logins/revoke', [
                'client_id' => $this->clientId(),
                'login_token' => $this->token(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        self::forget();
    }

    public static function forget(): void
    {
        if (is_file(self::path())) {
            unlink(self::path());
        }
    }

    public static function path(): string
    {
        return storage_path('core-web/remote-core.json');
    }

    private function save(): void
    {
        $dir = dirname(self::path());

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        file_put_contents(self::path(), json_encode($this->login, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        // A login token reads as the site's client, so only this machine's user may read it.
        chmod(self::path(), 0600);
    }

    /** @param  array<string, string>  $data */
    private static function post(string $url, array $data): Response
    {
        // Never CORE_CA_BUNDLE, which is for a local Core; a redirect would carry the login token on to wherever it points.
        return Http::acceptJson()->asForm()->connectTimeout(5)->timeout(15)->withoutRedirecting()->post($url, $data);
    }

    private static function refusal(Response $response, string $message): string
    {
        $reason = (string) ($response->json('error_description') ?: $response->json('message') ?: $response->json('error'));

        return "{$message} [{$response->status()}]".($reason !== '' ? ": {$reason}" : '.');
    }
}
