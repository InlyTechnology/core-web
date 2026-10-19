<?php

namespace Inly\CoreWeb\Media\Push;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/** The remote site's `/!/core-web/sync/assets` endpoints, called with the pushing person's sync token. */
final class HttpPushTransport implements PushTransport
{
    public const PATH = '/!/core-web/sync/assets';

    public function __construct(private readonly string $baseUrl, private readonly string $token) {}

    public function check(array $files): array
    {
        $answers = [];

        // In chunks, to stay inside the endpoint's limit per call.
        foreach (array_chunk($files, 200) as $chunk) {
            $response = $this->http()->timeout(60)->post(self::PATH.'/check', ['files' => $chunk])->throw();
            array_push($answers, ...array_values((array) $response->json('files', [])));
        }

        return $answers;
    }

    public function upload(string $container, string $path, string $sha256, $stream): array
    {
        $response = $this->http()
            ->timeout(300)
            ->attach('file', $stream, basename($path))
            ->post(self::PATH, ['container' => $container, 'path' => $path, 'sha256' => $sha256]);

        if ($response->status() === 422 && $response->json('status') === 'refused') {
            return ['status' => 'refused', 'path' => $path, 'reason' => (string) $response->json('reason')];
        }

        return $response->throw()->json();
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl, '/'))->withToken($this->token)->acceptJson()->connectTimeout(5);
    }
}
