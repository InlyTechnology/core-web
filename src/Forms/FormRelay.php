<?php

namespace Inly\CoreWeb\Forms;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inly\CoreWeb\Client\CoreClient;
use Inly\CoreWeb\ContentSync\RemoteSite;
use Inly\CoreWeb\CoreWebServiceProvider;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Core forms on a local checkout without a Core: the embed and the site's own
 * form classes reach the live site's Core through the live site, on this
 * machine's `content:login`. What is sent is a real entry, so every relayed
 * form says so (`<x-core-web::forms.relay-notice />`).
 */
final class FormRelay
{
    /** The embed files Core serves publicly, the only ones the relay fetches. */
    public const EMBED_FILES = ['v1.js', 'v1.css'];

    /** The headers of the embed's own calls that Core reads; every other one stays on this side. */
    private const FORWARDED_HEADERS = ['Accept', 'X-Core-Form-Site-Key', 'X-Core-Form-Nonce'];

    /** What the local relay says when the live site answers for no relay at all. */
    public const NO_RELAY = 'The live site does not offer the form relay: update core-web there and switch its forms feature on.';

    /**
     * Whether this checkout may relay at all: local, with forms and content
     * sync on, signed in to the live site, and asked from this machine itself
     * (RestrictToLoopback), so a page served to anyone else carries no relay.
     */
    public static function available(): bool
    {
        return app()->environment('local')
            && CoreWebServiceProvider::enabled('forms')
            && CoreWebServiceProvider::enabled('content_sync')
            && RestrictToLoopback::allows(request())
            && RemoteSite::fromStorage() !== null;
    }

    /** The live site's own origin, from its configured URL: the address Core allows its embeds from, never the Host a caller chose. */
    public static function siteOrigin(): ?string
    {
        $parts = parse_url((string) config('app.url'));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** An entry's uploads, refused before they are read: one that did not arrive whole, or all of them past the relay's limit. */
    public static function refusesUploads(Request $request): ?JsonResponse
    {
        $total = 0;

        foreach (self::parts($request->allFiles()) as [, $file]) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            if (! $file->isValid()) {
                return self::error('invalid_upload', 'A file did not arrive whole; try again.', 422);
            }

            $total += (int) $file->getSize();
        }

        $limit = (int) config('core-web.forms.relay_max_upload_bytes', 10485760);

        if ($total > $limit) {
            return self::error('payload_too_large', 'The files are larger than the form relay takes ('.intdiv($limit, 1048576).' MB in all).', 413);
        }

        return null;
    }

    /** Turnstile binds its answer to the page's host, which a relayed page never is, so such an entry is refused here with the reason. */
    public static function refusesTurnstile(Request $request): ?JsonResponse
    {
        return $request->headers->has('Cf-Turnstile-Response')
            ? self::error('turnstile_not_relayable', 'Turnstile forms cannot be relayed from a local checkout: Turnstile only accepts answers from the live site\'s own pages.', 422)
            : null;
    }

    /** The embed has no Core address of its own here, so it loads through the relay. */
    public static function relaysEmbeds(): bool
    {
        return CoreClient::fromConfig()->baseUrl() === '' && self::available();
    }

    /** The site's form classes have no client to send with here, so their entries go through the live site. */
    public static function relaysSubmissions(): bool
    {
        return ! CoreClient::fromConfig()->configured() && self::available();
    }

    /** The relay's root on this site, which the embed takes as its Core address. */
    public static function url(): string
    {
        return url('/'.trim((string) config('statamic.routes.action', '!'), '/').'/core-web/forms-relay');
    }

    /**
     * The incoming body onto an outgoing request: JSON as it came, a form
     * entry rebuilt as multipart part by part, since PHP keeps no raw copy of one.
     */
    public static function withBody(PendingRequest $outgoing, Request $incoming): PendingRequest
    {
        if ($incoming->isJson() || ($incoming->allFiles() === [] && $incoming->post() === [])) {
            return $outgoing->withBody($incoming->getContent(), 'application/json');
        }

        foreach (self::parts($incoming->post()) as [$name, $value]) {
            $outgoing->attach($name, (string) $value);
        }

        foreach (self::parts($incoming->allFiles()) as [$name, $file]) {
            if ($file instanceof UploadedFile) {
                $outgoing->attach($name, (string) $file->get(), $file->getClientOriginalName(), ['Content-Type' => (string) $file->getClientMimeType()]);
            }
        }

        return $outgoing;
    }

    /** @return array<string, string> */
    public static function forwardedHeaders(Request $incoming): array
    {
        $headers = [];

        foreach (self::FORWARDED_HEADERS as $name) {
            if ($incoming->headers->has($name)) {
                $headers[$name] = (string) $incoming->headers->get($name);
            }
        }

        return $headers;
    }

    /** Core's answer as the embed reads it: the status and the JSON body, nothing of the transport. */
    public static function answer(Response $response): HttpResponse
    {
        $json = $response->json();

        if (! is_array($json)) {
            return self::error('relay_failed', 'The live site answered something that is not JSON (HTTP '.$response->status().').', 502);
        }

        return new JsonResponse($json, $response->status(), array_filter(['Retry-After' => $response->header('Retry-After')]));
    }

    /** An error in the shape Core's own form endpoints answer with, which the embed shows. */
    public static function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /**
     * Nested form values flattened to multipart names (`photos[0]`).
     *
     * @param  array<array-key, mixed>  $values
     * @return list<array{0: string, 1: mixed}>
     */
    private static function parts(array $values, string $prefix = ''): array
    {
        $parts = [];

        foreach ($values as $key => $value) {
            $name = $prefix === '' ? (string) $key : "{$prefix}[{$key}]";
            array_push($parts, ...(is_array($value) ? self::parts($value, $name) : [[$name, $value]]));
        }

        return $parts;
    }
}
