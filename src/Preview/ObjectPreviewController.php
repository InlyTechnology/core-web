<?php

namespace Inly\CoreWeb\Preview;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;
use Inly\CoreWeb\Snapshots\Snapshot;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Statamic\Facades\Site;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\NullCacher;
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
        $input = $request->validate([
            'type' => ['required', 'string', 'max:128'],
            'id' => ['required', 'string', 'max:128'],
            'site' => ['required', 'string', 'max:64'],
            'state' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{48}$/'],
        ]);

        $core = self::coreOrigin();
        $snapshot = $pages->snapshotFor($input['type']);

        abort_if($core === null || $snapshot === null || Site::get($input['site']) === null, 404);

        $site = $input['site'];
        $saved = $pages->document($snapshot, $site, $input['id']);
        $previewed = blank($input['state'] ?? null) ? null : $this->previewed($snapshot, $site, $input['id'], (string) $input['state'], $context);

        [$documentId, $document] = $previewed ?? $saved ?? abort(404);

        return $this->framed($this->render($snapshot, $site, $documentId, $document, $previewed !== null, $store, $context), $core);
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
     * The page as the site's own route renders it, with the document in place of the stored one, never served from
     * or written to the static cache.
     *
     * @param  array<string, mixed>  $document
     */
    private function render(Snapshot $snapshot, string $site, string $documentId, array $document, bool $unsaved, SnapshotStore $store, PreviewContext $context): SymfonyResponse
    {
        $url = rtrim(Site::get($site)->absoluteUrl(), '/').ObjectPages::encodePath((string) $snapshot->pagePath($document, $site));
        $page = Request::create($url, 'GET', server: ['HTTP_ACCEPT' => 'text/html']);
        $original = app('request');
        $partition = $snapshot->partitionFor($site);

        // Statamic binds its cacher afresh for every resolve, so the stand-in is dropped again rather than swapped back.
        app()->instance(Cacher::class, new NullCacher);

        try {
            return $context->rendering(fn (): SymfonyResponse => $store->previewing(
                $snapshot,
                $partition === false ? null : $partition,
                [$documentId => $document],
                fn (): SymfonyResponse => app(Kernel::class)->handle($page),
            ));
        } finally {
            app()->forgetInstance(Cacher::class);
            app()->instance('request', $original);
            Facade::clearResolvedInstance('request');
        }
    }

    /** The page's answer for Core's frame alone: nothing of it cached, indexed or sent on in a Referer. */
    private function framed(SymfonyResponse $page, string $core): Response
    {
        $html = str_contains((string) $page->headers->get('Content-Type'), 'text/html');
        $content = (string) $page->getContent();

        if ($html && $page->getStatusCode() === 200) {
            $content = PreviewScript::inject($content, $core);
        }

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
