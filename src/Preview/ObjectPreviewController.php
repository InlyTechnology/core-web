<?php

namespace Inly\CoreWeb\Preview;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Validator;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Statamic\Facades\Site;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\NullCacher;
use Statamic\StaticCaching\NoCache\Session as NoCacheSession;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Core's preview frame of a record's page (Statamic connector API v1.6): the
 * record read from Core with the person's preview state applied, rendered
 * through the site's own route for the page, for this request only. Without
 * an applied state only a page the site serves publicly renders. See
 * docs/object-preview.md.
 */
class ObjectPreviewController
{
    public function __invoke(Request $request, ObjectPages $pages, SnapshotStore $store, PreviewContext $context): SymfonyResponse
    {
        // An unsealed frame route answers a malformed address as a missing page, never a redirect back to a Referer.
        $validator = Validator::make($request->query(), [
            'type' => ['required', 'string', 'max:128'],
            'id' => ['required', 'string', 'max:128'],
            'site' => ['required', 'string', 'max:64'],
            'state' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{48}$/'],
        ]);
        $core = self::coreOrigin();

        abort_if($validator->fails() || $core === null || Site::get((string) $request->query('site')) === null, 404);

        $input = $validator->validated();

        foreach ($pages->snapshotsFor($input['type']) as $snapshot) {
            $saved = $pages->document($snapshot, $input['site'], $input['id']);
            $previewed = blank($input['state'] ?? null) ? null : $this->previewed($snapshot, $input['site'], $input['id'], (string) $input['state'], $context);

            if (($previewed ?? $saved) !== null) {
                [$documentId, $document] = $previewed ?? $saved;
                $partition = $snapshot->partitionFor($input['site']) ?: null;
                $hidden = $previewed === null ? [] : array_values(array_diff($pages->storedIds($snapshot, $partition, $input['id']), [$documentId]));

                return $this->framed($this->render($request, $snapshot, $input['site'], $partition, $documentId, $document, $hidden, $store, $context), $core);
            }
        }

        abort(404);
    }

    /**
     * The record as Core reads it with the state applied, null when Core did not apply it (no live state, or one the
     * site's client may not read) or the record then has no page on the site.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function previewed(Snapshot $snapshot, string $site, string $id, string $state, PreviewContext $context): ?array
    {
        $partition = $snapshot->partitionFor($site);

        if ($partition === false || ! $snapshot->pullsOne()) {
            return null;
        }

        try {
            [$documents, $applied] = $context->reading($state, fn (): array => $snapshot->pullDocument($id));
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! $applied) {
            return null;
        }

        foreach ($snapshot->documentsIn($documents, $partition) as $documentId => $document) {
            if ($snapshot->pagePath($document, $site) !== null) {
                return [(string) $documentId, $document];
            }
        }

        return null;
    }

    /**
     * The page as the site's own route renders it, with the document in place of the stored one. Nothing the render
     * makes is kept: not the page, its nocache regions or its cache tags, which the public page would read back.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>  $hidden
     */
    private function render(Request $request, Snapshot $snapshot, string $site, ?string $partition, string $documentId, array $document, array $hidden, SnapshotStore $store, PreviewContext $context): SymfonyResponse
    {
        $url = rtrim(Site::get($site)->absoluteUrl(), '/').ObjectPages::encodePath((string) $snapshot->pagePath($document, $site));
        $page = Request::create($url, 'GET', server: self::visitor($request));
        $original = app('request');
        $session = app()->resolved(NoCacheSession::class) ? app(NoCacheSession::class) : null;
        $cacheTags = config('statamic.system.cache_tags_enabled', true);

        // Statamic binds its cacher afresh for every resolve, so the stand-in is dropped again rather than swapped back.
        app()->instance(Cacher::class, new NullCacher);
        app()->instance(NoCacheSession::class, new PreviewNoCacheSession($url));
        config(['statamic.system.cache_tags_enabled' => false]);

        try {
            return $context->rendering(fn (): SymfonyResponse => $store->previewing(
                $snapshot,
                $partition,
                [$documentId => $document],
                fn (): SymfonyResponse => app(Kernel::class)->handle($page),
                $hidden,
            ));
        } finally {
            app()->forgetInstance(Cacher::class);
            $session === null ? app()->forgetInstance(NoCacheSession::class) : app()->instance(NoCacheSession::class, $session);
            config(['statamic.system.cache_tags_enabled' => $cacheTags]);

            // The router keeps the page's route as current; nothing after this answer reads it.
            app()->instance('request', $original);
            Facade::clearResolvedInstance('request');
        }
    }

    /**
     * The visitor's address and the proxy headers that carry it, so the page's own throttles and loopback checks see
     * them rather than the server itself.
     *
     * @return array<string, string>
     */
    private static function visitor(Request $request): array
    {
        $server = ['HTTP_ACCEPT' => 'text/html', 'REMOTE_ADDR' => (string) $request->server('REMOTE_ADDR', '')];

        foreach ($request->server->all() as $key => $value) {
            if (is_string($value) && (str_starts_with($key, 'HTTP_X_FORWARDED_') || in_array($key, ['HTTP_FORWARDED', 'HTTP_USER_AGENT', 'HTTP_ACCEPT_LANGUAGE'], true))) {
                $server[$key] = $value;
            }
        }

        return $server;
    }

    /** The page's answer for Core's frame alone: nothing of it cached, indexed or sent on in a Referer. */
    private function framed(SymfonyResponse $page, string $core): Response
    {
        $html = str_contains((string) $page->headers->get('Content-Type'), 'text/html');
        $content = (string) $page->getContent();

        if ($html && $page->getStatusCode() === 200) {
            $content = PreviewScript::inject($content, $core);
        }

        // A redirect (a canonical slug, a trailing slash) is not followed: the frame shows only the page itself.
        return response($content, $page->getStatusCode() === 200 ? 200 : 404, [
            'Content-Type' => $page->headers->get('Content-Type') ?? 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "frame-ancestors {$core}",
        ]);
    }

    /** Core's origin (scheme, host and port), the only page that may frame the preview. */
    public static function coreOrigin(): ?string
    {
        $parts = parse_url((string) config('core-web.client.base_url'));

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['https', 'http'], true) || blank($parts['host'] ?? null)) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
