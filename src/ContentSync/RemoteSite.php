<?php

namespace Inly\CoreWeb\ContentSync;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The live site, as this local checkout talks to it: its URL (committed
 * config, content_sync.remote_url) and this machine's login to it, a
 * personal token acting as the developer's CMS user, got by approving the
 * machine in the site's CP (`content:login`). The login is kept in
 * storage/core-web/remote-site.json, readable by this user only. Every call
 * sends it as the bearer and follows no redirect; the sync endpoints' own 401
 * forgets it and throws RemoteLoginNeeded.
 */
final class RemoteSite
{
    /**
     * @param  array{name: string, email: string}  $user
     */
    public function __construct(
        private string $url,
        private string $token,
        private array $user,
        private ?CarbonImmutable $expiresAt,
    ) {}

    /** The configured remote site, without a trailing slash; '' when none is set. */
    public static function configuredUrl(): string
    {
        return rtrim((string) config('core-web.content_sync.remote_url', ''), '/');
    }

    /** This machine's stored login to the configured site; null when there is none, it expired, or it was made for another URL. */
    public static function fromStorage(): ?self
    {
        $site = self::stored();

        if ($site === null || $site->url !== self::configuredUrl() || $site->expiresAt?->isPast()) {
            return null;
        }

        return $site;
    }

    /** Whatever login is stored, for whichever URL and however old: what signing out revokes. */
    public static function stored(): ?self
    {
        $file = self::loginFile();
        $data = is_file($file) && is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

        if (! is_array($data) || ! is_string($data['token'] ?? null) || ! is_string($data['url'] ?? null) || rtrim($data['url'], '/') === '') {
            return null;
        }

        try {
            $expiresAt = blank($data['expires_at'] ?? null) ? null : CarbonImmutable::parse($data['expires_at']);
        } catch (Throwable) {
            $expiresAt = null;
        }

        return new self(rtrim($data['url'], '/'), $data['token'], [
            'name' => (string) ($data['user']['name'] ?? ''),
            'email' => (string) ($data['user']['email'] ?? ''),
        ], $expiresAt);
    }

    /** @param  array{name?: string, email?: string}  $user */
    public static function store(string $url, string $token, array $user, ?string $expiresAt): self
    {
        $site = new self(rtrim($url, '/'), $token, ['name' => (string) ($user['name'] ?? ''), 'email' => (string) ($user['email'] ?? '')], $expiresAt === null ? null : CarbonImmutable::parse($expiresAt));
        $site->save();

        return $site;
    }

    public static function forget(): void
    {
        File::delete(self::loginFile());
    }

    public static function loginFile(): string
    {
        return storage_path('core-web/remote-site.json');
    }

    /**
     * A request to a site with no login yet, for the device flow: JSON,
     * timeouts, HTTPS unless the site is a local one, and no redirects, which
     * would carry the bearer token or the device code to another host.
     */
    public static function anonymous(string $url): PendingRequest
    {
        self::assertSecure($url);

        return Http::baseUrl(rtrim($url, '/'))
            ->withoutRedirecting()
            ->acceptJson()
            ->connectTimeout(max(1, (int) config('core-web.content_sync.timeouts.connect', 5)))
            ->timeout(max(1, (int) config('core-web.content_sync.timeouts.request', 30)));
    }

    /** The path of a sync endpoint on the site, under Statamic's action prefix. */
    public static function endpoint(string $name): string
    {
        return '/'.trim((string) config('statamic.routes.action', '!'), '/').'/core-web/sync/'.ltrim($name, '/');
    }

    /** Tokens and content travel over HTTPS, except to a site on this machine (a `.test` host, localhost). */
    public static function assertSecure(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = (string) parse_url($url, PHP_URL_HOST);

        if ($scheme === 'https') {
            return;
        }

        if ($scheme === 'http' && (str_ends_with($host, '.test') || in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true))) {
            return;
        }

        throw new RemoteSiteError("The remote site [{$url}] must be an https:// URL (core-web.content_sync.remote_url).");
    }

    /**
     * Turns an answer a sync cannot use into a RemoteSiteError that says why:
     * a redirect (remote_url names the wrong address) or a rate limit.
     *
     * @throws RemoteSiteError
     */
    public static function assertUsable(string $url, Response $response, string $requested = ''): void
    {
        if ($response->redirect()) {
            $location = $response->header('Location');

            throw new RemoteSiteError("core-web.content_sync.remote_url [{$url}] redirects to ".($location === '' ? 'another address' : '['.self::landing($location, $requested).']').'; set that URL instead.');
        }

        if ($response->status() === 429) {
            $retry = $response->header('Retry-After');

            throw new RemoteSiteBusy("{$url} is rate limiting this machine (HTTP 429)".(ctype_digit($retry) ? "; try again in {$retry} seconds" : '; try again in a minute').'.', ctype_digit($retry) ? (int) $retry : null);
        }
    }

    public function url(): string
    {
        return $this->url;
    }

    /** The bearer token, for a client of the sync endpoints beside this one (a push transport). */
    public function token(): string
    {
        return $this->token;
    }

    /** @return array{name: string, email: string} the CMS user this machine acts as */
    public function user(): array
    {
        return $this->user;
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return $this->expiresAt;
    }

    /**
     * GET a sync endpoint by name (`export`), or any path from the site's
     * root when it starts with a slash.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws RemoteLoginNeeded|RemoteSiteError
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->send($this->path($path), fn (PendingRequest $request, string $endpoint) => $request->get($endpoint, $query));
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws RemoteLoginNeeded|RemoteSiteError
     */
    public function post(string $path, array $data = []): Response
    {
        return $this->send($this->path($path), fn (PendingRequest $request, string $endpoint) => $request->post($endpoint, $data));
    }

    /**
     * POSTs a file as multipart form data, beside the fields given.
     *
     * @param  array<string, string>  $data
     *
     * @throws RemoteLoginNeeded|RemoteSiteError
     */
    public function upload(string $path, array $data, string $filename, string $bytes): Response
    {
        return $this->send($this->path($path), fn (PendingRequest $request, string $endpoint) => $request->attach('file', $bytes, $filename)->post($endpoint, $data));
    }

    /** Revokes this machine's token on the site and forgets it here; the site being unreachable still forgets it here. */
    public function logout(): bool
    {
        try {
            return $this->revoke();
        } finally {
            self::forget();
        }
    }

    /** Revokes the token on the site, keeping whatever is stored here; false when the site could not be told (a token it already refuses counts as revoked). */
    public function revoke(): bool
    {
        try {
            return $this->post('logout')->successful();
        } catch (RemoteLoginNeeded) {
            return true;
        } catch (RemoteSiteError) {
            // The token then expires on the site unused, or is revoked in the CP.
            return false;
        }
    }

    /** @param  callable(PendingRequest, string): Response  $call */
    private function send(string $endpoint, callable $call): Response
    {
        try {
            $response = $call(self::anonymous($this->url)->withToken($this->token), $endpoint);
        } catch (ConnectionException $e) {
            throw new RemoteSiteError("Could not reach {$this->url}: {$e->getMessage()}", previous: $e);
        }

        self::assertUsable($this->url, $response, $endpoint);

        if ($response->status() === 401 && $response->json('error') === AuthenticateSyncToken::REFUSED) {
            self::forget();

            throw new RemoteLoginNeeded($this->url);
        }

        if ($response->status() === 401) {
            throw new RemoteSiteError("{$this->url} answered HTTP 401 from in front of its sync endpoints (basic auth on a staging host?); this machine's login is kept.");
        }

        $this->keepExpiry($response);

        return $response;
    }

    /** The site slides the token's expiry on every use and says where to, so the stored login does not expire here early. */
    private function keepExpiry(Response $response): void
    {
        $header = $response->header(AuthenticateSyncToken::EXPIRES_HEADER);

        if ($header === '') {
            return;
        }

        try {
            $expiresAt = CarbonImmutable::parse($header);
        } catch (Throwable) {
            return;
        }

        if ($this->expiresAt?->equalTo($expiresAt)) {
            return;
        }

        $this->expiresAt = $expiresAt;
        $this->save();
    }

    /** The remote_url a redirect points at: the location without the endpoint path it was sent to, or its origin. */
    private static function landing(string $location, string $requested): string
    {
        $parts = parse_url($location) ?: [];
        $path = (string) ($parts['path'] ?? '');
        $origin = isset($parts['scheme'], $parts['host']) ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '') : '';

        if ($requested !== '' && str_ends_with($path, $requested)) {
            return rtrim($origin.substr($path, 0, -strlen($requested)), '/');
        }

        return $origin !== '' ? $origin : $location;
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/') ? $path : self::endpoint($path);
    }

    private function save(): void
    {
        $file = self::loginFile();
        File::ensureDirectoryExists(dirname($file), 0700);

        // A Laravel app ignores only storage/'s own subfolders, so this one says it itself: a token never reaches git.
        if (! is_file($ignore = dirname($file).'/.gitignore')) {
            file_put_contents($ignore, "*\n");
        }

        // Created readable by this user only before the token goes in.
        touch($file);
        chmod($file, 0600);
        file_put_contents($file, json_encode([
            'url' => $this->url,
            'token' => $this->token,
            'user' => $this->user,
            'expires_at' => $this->expiresAt?->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
