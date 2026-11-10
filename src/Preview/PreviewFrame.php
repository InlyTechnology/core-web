<?php

namespace Inly\CoreWeb\Preview;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;
use Statamic\StaticCaching\Cacher;
use Statamic\StaticCaching\Cachers\NullCacher;
use Statamic\StaticCaching\NoCache\Session as NoCacheSession;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A page rendered for Core's preview frame: through the site's own route for its address, for this request only, and
 * answered for that frame alone. The object preview and the page preview both render through it
 * (docs/object-preview.md).
 */
final class PreviewFrame
{
    public function __construct(private PreviewContext $context) {}

    /**
     * The page at `$url` as the site's own route renders it, with the markers printed. `$around` wraps the render, to
     * stand a previewed document in for the stored one. Nothing the render makes is kept: not the page, its nocache
     * regions or its cache tags, which the public page would read back.
     *
     * @param  (Closure(Closure(): SymfonyResponse): SymfonyResponse)|null  $around
     */
    public function render(Request $request, string $url, ?Closure $around = null): SymfonyResponse
    {
        $page = Request::create($url, 'GET', server: self::visitor($request));
        $original = app('request');
        $session = app()->resolved(NoCacheSession::class) ? app(NoCacheSession::class) : null;
        $cacheTags = config('statamic.system.cache_tags_enabled', true);
        $handle = fn (): SymfonyResponse => app(Kernel::class)->handle($page);

        // Statamic binds its cacher afresh for every resolve, so the stand-in is dropped again rather than swapped back.
        app()->instance(Cacher::class, new NullCacher);
        app()->instance(NoCacheSession::class, new PreviewNoCacheSession($url));
        config(['statamic.system.cache_tags_enabled' => false]);

        try {
            return $this->context->rendering(fn (): SymfonyResponse => $around === null ? $handle() : $around($handle));
        } finally {
            app()->forgetInstance(Cacher::class);
            $session === null ? app()->forgetInstance(NoCacheSession::class) : app()->instance(NoCacheSession::class, $session);
            config(['statamic.system.cache_tags_enabled' => $cacheTags]);

            // The router keeps the page's route as current; nothing after this answer reads it.
            app()->instance('request', $original);
            Facade::clearResolvedInstance('request');
        }
    }

    /** The page's answer for Core's frame alone: nothing of it cached, indexed or sent on in a Referer. */
    public static function answer(SymfonyResponse $page, string $core): Response
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

    /** Core's origin (scheme, host and port), the only page that may frame a preview. */
    public static function coreOrigin(): ?string
    {
        $parts = parse_url((string) config('core-web.client.base_url'));

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['https', 'http'], true) || blank($parts['host'] ?? null)) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
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
}
