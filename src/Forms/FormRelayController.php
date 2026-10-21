<?php

namespace Inly\CoreWeb\Forms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Inly\CoreWeb\Client\CoreClient;
use Symfony\Component\HttpFoundation\Response;

/**
 * The live site's half of the form relay, on a machine's sync token: a local
 * checkout's embed calls reach this site's Core as this site's own, so the
 * origin Core allows its embeds from is this site's address. Only the forms
 * in this site's form list, so the token reaches no other Core form.
 */
class FormRelayController
{
    public function script(string $file): Response
    {
        $core = CoreClient::fromConfig();

        if ($core->baseUrl() === '') {
            return FormRelay::error('relay_unavailable', 'The live site has no Core to load the form embed from.', 503);
        }

        try {
            $response = CoreClient::request()
                ->withoutRedirecting()
                ->connectTimeout((int) config('core-web.forms.timeouts.connect', 3))
                ->timeout((int) config('core-web.forms.timeouts.definition', 5))
                ->get($core->baseUrl().'/public/forms/embed/'.$file);
        } catch (ConnectionException) {
            return FormRelay::error('relay_unavailable', 'The live site could not reach its Core.', 502);
        }

        if (! $response->successful()) {
            return FormRelay::error('relay_unavailable', "The live site's Core answered HTTP {$response->status()} for the embed.", 502);
        }

        return response($response->body(), 200, ['Content-Type' => $response->header('Content-Type') ?: 'application/javascript']);
    }

    public function definition(Request $request, string $uuid): Response
    {
        return $this->forward($request, $uuid, fn (PendingRequest $core, string $url) => $core->get($url.'/definition', array_filter([
            'sk' => self::string($request->query('sk')),
            'locale' => self::string($request->query('locale')),
        ])));
    }

    public function nonce(Request $request, string $uuid): Response
    {
        return $this->forward($request, $uuid, fn (PendingRequest $core, string $url) => $core->get($url.'/nonce'));
    }

    public function submit(Request $request, string $uuid): Response
    {
        if ($refused = FormRelay::refusesTurnstile($request) ?? FormRelay::refusesUploads($request)) {
            return $refused;
        }

        return $this->forward($request, $uuid, fn (PendingRequest $core, string $url) => FormRelay::withBody($core, $request)
            ->timeout((int) config('core-web.forms.timeouts.submit', 20))
            ->post($url.'/submit'));
    }

    /** An entry from a local checkout's form class, sent on this site's own client token as the class would here. */
    public function entry(Request $request, string $uuid, FormSubmitter $submitter): Response
    {
        $form = CoreForms::fromContainer()->formByUuid($uuid);

        // Only what a form class would send here: an embed's form goes through the embed's own calls.
        if ($form === null || ! in_array($form['key'] ?? null, CoreForms::renderedKeys(), true)) {
            return self::unknown();
        }

        if ($refused = FormRelay::refusesUploads($request)) {
            return $refused;
        }

        $payload = $request->input('payload');

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        if (! is_array($payload)) {
            return FormRelay::error('invalid_request', 'Send the entry\'s values as `payload`.', 422);
        }

        $files = [];

        foreach (Arr::except($request->allFiles(), ['payload']) as $field => $list) {
            $files[(string) $field] = array_values(array_filter(Arr::wrap($list), fn (mixed $file) => $file instanceof UploadedFile));
        }

        $result = $submitter->submit($uuid, $payload, $files);

        return new JsonResponse(['result' => $result->value, 'errors' => (object) $submitter->errors()]);
    }

    /** @param  callable(PendingRequest, string): \Illuminate\Http\Client\Response  $call */
    private function forward(Request $request, string $uuid, callable $call): Response
    {
        $core = CoreClient::fromConfig();

        if ($core->baseUrl() === '') {
            return FormRelay::error('relay_unavailable', 'The live site has no Core to send forms to.', 503);
        }

        if (CoreForms::fromContainer()->formByUuid($uuid) === null) {
            return self::unknown();
        }

        $origin = FormRelay::siteOrigin();

        if ($origin === null) {
            return FormRelay::error('relay_unavailable', 'The live site has no app.url to send forms from.', 503);
        }

        $outgoing = self::core()->withHeaders([
            ...FormRelay::forwardedHeaders($request),
            // The address Core allows this site's embeds from, and binds a nonce to.
            'Origin' => $origin,
        ]);

        try {
            return FormRelay::answer($call($outgoing, $core->baseUrl().'/api/forms/'.rawurlencode($uuid)));
        } catch (ConnectionException) {
            return FormRelay::error('relay_unavailable', 'The live site could not reach its Core.', 502);
        }
    }

    private static function core(): PendingRequest
    {
        return CoreClient::request()
            ->withoutRedirecting()
            ->acceptJson()
            ->connectTimeout((int) config('core-web.forms.timeouts.connect', 3))
            ->timeout((int) config('core-web.forms.timeouts.definition', 5));
    }

    private static function unknown(): JsonResponse
    {
        return FormRelay::error('unknown_form', 'The live site has no such form in its form list.', 404);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
