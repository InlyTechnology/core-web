<?php

namespace Inly\CoreWeb\Forms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Inly\CoreWeb\Client\CoreClient;
use JsonException;
use RuntimeException;

/**
 * Posts one entry to a Core form server to server
 * (POST {core}/api/forms/{uuid}/submit) on the site's client token, which
 * Core takes for a form linked to the site's app client. With files the
 * JSON travels as the multipart part `payload`. Values are personal data:
 * logs carry the form, the status, Core's error code and the names of the
 * fields Core refused, never a value.
 */
final class FormSubmitter
{
    /** @var array<string, string> the fields Core refused in the last submission, by name */
    private array $errors = [];

    public function __construct(private readonly CoreClient $core) {}

    /**
     * @param  array<string, mixed>  $payload  field name => value
     * @param  array<string, list<UploadedFile>>  $files  field name => the files sent in it
     */
    public function submit(string $uuid, array $payload, array $files = []): SubmissionResult
    {
        $this->errors = [];

        if (! $this->core->configured()) {
            Log::error('core-web forms: submission not sent, Core is not configured', ['form' => $uuid]);

            return SubmissionResult::UNAVAILABLE;
        }

        try {
            $body = json_encode((object) $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Log::error('core-web forms: submission not sent, its values do not encode', ['form' => $uuid]);

            return SubmissionResult::UNAVAILABLE;
        }

        $url = $this->core->baseUrl().'/api/forms/'.rawurlencode($uuid).'/submit';

        try {
            $response = $this->core->withToken(fn (PendingRequest $request) => $this->send($request, $url, $body, $files));
        } catch (ConnectionException|RuntimeException $e) {
            Log::warning('core-web forms: submission failed', ['form' => $uuid, 'error' => $e::class]);

            return SubmissionResult::UNAVAILABLE;
        }

        return $this->result($uuid, $response);
    }

    /**
     * Core's message per field it refused in the last submission.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @param  array<string, list<UploadedFile>>  $files */
    private function send(PendingRequest $request, string $url, string $body, array $files): Response
    {
        $request = $request
            ->acceptJson()
            ->connectTimeout((int) config('core-web.forms.timeouts.connect', 3))
            ->timeout((int) config('core-web.forms.timeouts.submit', 20));

        if (array_filter($files) === []) {
            return $request->withBody($body, 'application/json')->post($url);
        }

        $request->attach('payload', $body, 'payload.json', ['Content-Type' => 'application/json']);

        foreach ($files as $field => $list) {
            foreach (array_values($list) as $i => $file) {
                $request->attach($field.'[]', (string) $file->get(), self::fileName($file, $i), ['Content-Type' => (string) $file->getMimeType()]);
            }
        }

        return $request->post($url);
    }

    private function result(string $uuid, Response $response): SubmissionResult
    {
        $code = (string) $response->json('error.code', '');
        $context = ['form' => $uuid, 'status' => $response->status(), 'code' => $code !== '' ? $code : null];

        if ($response->successful()) {
            return SubmissionResult::SENT;
        }

        if ($response->status() === 422) {
            $this->errors = array_map(
                fn (mixed $message) => is_array($message) ? (string) reset($message) : (string) $message,
                array_filter((array) $response->json('error.fields', []), fn (mixed $message, mixed $field) => is_string($field), ARRAY_FILTER_USE_BOTH),
            );
            Log::notice('core-web forms: Core refused a submission', [...$context, 'fields' => array_keys($this->errors)]);

            return SubmissionResult::INVALID;
        }

        if ($response->status() === 429) {
            Log::warning('core-web forms: Core throttled a submission', $context);

            return SubmissionResult::THROTTLED;
        }

        // A refused credential, a switched-off form or one that takes no files: an outage to the visitor.
        Log::log($response->serverError() ? 'warning' : 'error', 'core-web forms: submission failed', $context);

        return SubmissionResult::UNAVAILABLE;
    }

    /** A neutral name: the visitor's own file name is personal data. */
    private static function fileName(UploadedFile $file, int $index): string
    {
        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin';

        return 'file-'.($index + 1).'.'.strtolower($extension);
    }
}
