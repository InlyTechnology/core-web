<?php

namespace Inly\CoreWeb\Media\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Inly\CoreWeb\ContentSync\AuthenticateSyncToken;
use Inly\CoreWeb\ContentSync\RemoteLoginNeeded;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\ContentSync\RemoteSiteError;

/** The remote site's `sync/assets` endpoints, called with this machine's content:login token, as RemoteSite calls the others. */
final class HttpPushTransport implements PushTransport
{
    public function __construct(private readonly RemoteSite $site) {}

    /** @throws RemoteLoginNeeded when this machine has no login to the configured site */
    public static function fromStoredLogin(): self
    {
        $site = RemoteSite::fromStorage();

        if ($site === null) {
            throw new RemoteLoginNeeded(RemoteSite::configuredUrl());
        }

        return new self($site);
    }

    public function check(array $files): array
    {
        $answers = [];

        // In chunks, to stay inside the endpoint's limit per call.
        foreach (array_chunk($files, 200) as $chunk) {
            $response = $this->send('assets/check', fn (PendingRequest $request, string $endpoint) => $request->timeout(60)->post($endpoint, ['files' => $chunk]));
            array_push($answers, ...array_values((array) $response->throw()->json('files', [])));
        }

        return $answers;
    }

    public function upload(string $container, string $path, string $sha256, $stream): array
    {
        $response = $this->send('assets', fn (PendingRequest $request, string $endpoint) => $request
            ->timeout(300)
            ->attach('file', $stream, basename($path))
            ->post($endpoint, ['container' => $container, 'path' => $path, 'sha256' => $sha256]));

        if ($response->status() === 422 && $response->json('status') === 'refused') {
            return ['status' => 'refused', 'path' => $path, 'reason' => (string) $response->json('reason')];
        }

        return (array) $response->throw()->json();
    }

    /**
     * @param  callable(PendingRequest, string): Response  $call
     *
     * @throws RemoteLoginNeeded|RemoteSiteError
     */
    private function send(string $name, callable $call): Response
    {
        $endpoint = RemoteSite::endpoint($name);

        try {
            $response = $call(RemoteSite::anonymous($this->site->url())->withToken($this->site->token()), $endpoint);
        } catch (ConnectionException $e) {
            throw new RemoteSiteError("Could not reach {$this->site->url()}: {$e->getMessage()}", previous: $e);
        }

        RemoteSite::assertUsable($this->site->url(), $response, $endpoint);

        // Only the sync middleware's own refusal ends the login, as for a pull.
        if ($response->status() === 401 && $response->json('error') === AuthenticateSyncToken::REFUSED) {
            RemoteSite::forget();

            throw new RemoteLoginNeeded($this->site->url());
        }

        return $response;
    }
}
