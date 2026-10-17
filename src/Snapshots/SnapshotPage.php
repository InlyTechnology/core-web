<?php

namespace Inly\CoreWeb\Snapshots;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Site;
use Statamic\View\View;

/**
 * A page of one snapshot document, for a route the site declares itself:
 * find the document or answer for its absence, send a stray address to the
 * document's own, and render through Statamic's View so the layout gets the
 * cascade. Only for a snapshot that declares pages (Snapshot::pages()).
 */
final class SnapshotPage
{
    private function __construct(
        private readonly Snapshot $snapshot,
        private readonly SnapshotStore $store,
        private readonly string $site,
    ) {
        if (! $snapshot->hasPages()) {
            throw new LogicException("Snapshot [{$snapshot->type}] has no pages: declare them with pages().");
        }
    }

    /** The snapshot's pages on a site, the current one by default. */
    public static function for(Snapshot|string $snapshot, ?string $site = null): self
    {
        return new self(
            is_string($snapshot) ? app(SnapshotRegistry::class)->get($snapshot) : $snapshot,
            app(SnapshotStore::class),
            $site ?? Site::current()->handle(),
        );
    }

    /**
     * The document with a page here: by its id, or the first a closure picks
     * (given the document and its id). None is a 503 while nothing at all
     * can be read, since a 404 then would deindex every page at once, and
     * Statamic's 404 otherwise: Laravel's abort(404) renders a layout without
     * its cascade and fails.
     *
     * @param  string|Closure(array<string, mixed>, string): bool  $key
     * @return array<string, mixed>
     */
    public function find(string|Closure $key): array
    {
        $partition = $this->snapshot->partitionFor($this->site);

        if ($partition === false) {
            throw new NotFoundHttpException;
        }

        $read = $this->store->read($this->snapshot, $partition);
        $document = is_string($key) ? ($read->documents[$key] ?? null) : self::first($read->documents, $key);

        if ($document !== null && $this->path($document) !== null) {
            return $document;
        }

        abort_if($read->degraded, 503);

        throw new NotFoundHttpException;
    }

    /**
     * The document's page as a path on the site, null when it has none here.
     *
     * @param  array<string, mixed>  $document
     */
    public function path(array $document): ?string
    {
        return $this->snapshot->pagePath($document, $this->site);
    }

    /**
     * A 301 to the document's own page when the request reached it at
     * another path (an old slug, a renamed brand). The query string goes
     * along unless `$keepQuery` is false: it carries campaign tags and
     * filters, and the page's canonical link already ignores it.
     *
     * @param  array<string, mixed>  $document
     */
    public function canonical(Request $request, array $document, bool $keepQuery = true): ?RedirectResponse
    {
        $path = $this->path($document);

        if ($path === null || rawurldecode($this->requestPath($request)) === rawurldecode($path)) {
            return null;
        }

        $query = $keepQuery ? $request->getQueryString() : null;
        $url = rtrim(Site::get($this->site)->absoluteUrl(), '/').$path;

        return redirect()->to($query === null ? $url : "{$url}?{$query}", 301);
    }

    /**
     * The template rendered through Statamic, with `$cascade` (an entry, say
     * the listing's) as the cascade's content when the layout should read it.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data = [], mixed $cascade = null): View
    {
        return View::make($template, $data)->cascadeContent($cascade);
    }

    /** The request's path on the site, without the path a site under a shared host lives at. */
    private function requestPath(Request $request): string
    {
        $path = '/'.trim($request->path(), '/');
        $prefix = rtrim((string) parse_url(Site::get($this->site)->absoluteUrl(), PHP_URL_PATH), '/');

        if ($prefix === '' || ! str_starts_with($path.'/', $prefix.'/')) {
            return $path;
        }

        return substr($path, strlen($prefix)) ?: '/';
    }

    /**
     * @param  array<string, array<string, mixed>>  $documents
     * @param  Closure(array<string, mixed>, string): bool  $pick
     * @return array<string, mixed>|null
     */
    private static function first(array $documents, Closure $pick): ?array
    {
        foreach ($documents as $id => $document) {
            if ($pick($document, (string) $id)) {
                return $document;
            }
        }

        return null;
    }
}
