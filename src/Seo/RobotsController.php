<?php

namespace Inly\CoreWeb\Seo;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Sitemaps\SnapshotSitemaps;
use Statamic\Facades\Site;

/**
 * /robots.txt for the host asked: the global's own rules, the AI crawlers kept out, and the sitemap of every site on
 * this host. AI training crawlers are always blocked; the search and assistant agents only when the site says so
 * (core-web.seo.robots.ai_search), since blocking them takes the site out of AI answers.
 */
final class RobotsController
{
    public const AI_TRAINING = ['Applebot-Extended', 'anthropic-ai', 'Bytespider', 'ClaudeBot', 'cohere-ai', 'Diffbot', 'CCBot', 'FacebookBot', 'GPTBot', 'Google-Extended', 'ImagesiftBot', 'Omgili', 'Omgilibot'];

    public const AI_SEARCH = ['ChatGPT-User', 'OAI-SearchBot', 'PerplexityBot', 'Perplexity-User', 'Claude-User', 'Claude-SearchBot'];

    public function __invoke(Request $request): Response
    {
        $host = $request->getHost();
        $sites = Site::all()->filter(fn ($site) => parse_url($site->absoluteUrl(), PHP_URL_HOST) === $host)->values();
        $current = $sites->first() ?? Site::current();

        $sitemaps = $sites->filter(fn ($site) => Sitemaps::enabled($site->handle()))
            ->map(fn ($site) => rtrim($site->absoluteUrl(), '/').'/sitemap.xml')
            ->all();

        if (CoreWebServiceProvider::enabled('snapshots') && config('core-web.sitemaps.enabled', true)) {
            foreach ($sites as $site) {
                foreach (app(SnapshotSitemaps::class)->types($site->handle()) as $type) {
                    $sitemaps[] = rtrim($site->absoluteUrl(), '/').'/'.SnapshotSitemaps::path($type);
                }
            }
        }

        // Peak SEO's default for the field repeats the catch-all every robots.txt ends with.
        $rules = trim((string) Seo::get('robots', '', $current->handle()));
        $rules = preg_replace('/\s+/', ' ', $rules) === 'User-agent: * Allow: /' ? '' : $rules;

        $body = view((string) config('core-web.seo.robots.view', 'core-web::seo.robots'), [
            'rules' => $rules,
            'training' => self::AI_TRAINING,
            'search' => self::AI_SEARCH,
            'aiSearch' => (bool) config('core-web.seo.robots.ai_search', true),
            'sitemaps' => array_values(array_unique($sitemaps)),
        ])->render();

        return response(preg_replace("/\n{3,}/", "\n\n", ltrim($body)))->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
