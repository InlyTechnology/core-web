<?php

namespace Inly\CoreWeb\Seo;

use Inly\CoreWeb\Support\Values;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Cascade;
use Statamic\Facades\Site;
use Statamic\Statamic;
use Throwable;

/**
 * Everything <x-core-web::seo.head> renders for one page, worked out here so the view only prints it. The content is
 * the cascade's (an entry or a term); a page outside the cascade (a controller's route) passes its own title and
 * description and is indexed at its own URL.
 */
final class SeoHead
{
    /**
     * @param  array{title?: ?string, description?: ?string, image?: mixed, url?: ?string, type?: ?string, noindex?: ?bool}  $overrides
     * @return array<string, mixed>
     */
    public static function for(mixed $content = null, array $overrides = []): array
    {
        $content = Seo::content($content ?? self::cascade('page'));
        $card = SocialCard::for($content);
        $site = Site::current();

        $title = $overrides['title'] ?? self::text($content?->value('title')) ?? self::text(self::cascade('title')) ?? '';
        $description = self::text($content?->value('seo_description')) ?? $overrides['description'] ?? $card['description'];
        $noindex = $overrides['noindex'] ?? Seo::noindex($content);
        $nofollow = (bool) $content?->value('seo_nofollow');
        $indexes = Seo::indexesEnvironment();
        $permalink = $content?->absoluteUrl();
        $url = $overrides['url'] ?? $permalink ?? $card['url'];
        $ogTitle = self::text($content?->value('og_title')) ?? self::text($content?->value('seo_title')) ?? $title;
        $ogDescription = self::text($content?->value('og_description')) ?? $description;
        $image = array_key_exists('image', $overrides) && $overrides['image'] !== null
            ? SocialCard::imageUrl($overrides['image'])
            : $card['image_url'];

        return [
            'title' => self::pageTitle($content, $title),
            'description' => $description,
            'robots' => self::robots($indexes, $noindex, $nofollow),
            'canonical' => $indexes && ! $noindex ? self::canonical($content, $url) : null,
            'alternates' => $indexes && ! $noindex ? self::alternates($content) : [],
            'og' => array_filter([
                'og:site_name' => self::siteName(),
                'og:type' => $overrides['type'] ?? $card['type'],
                'og:locale' => $site->locale(),
                'og:url' => $url,
                'og:title' => $ogTitle,
                'og:description' => $ogDescription,
                'og:image' => $image,
                'og:image:alt' => $image === $card['image_url'] ? $card['image_alt'] : null,
            ]),
            'twitter' => array_filter([
                'twitter:card' => $image ? 'summary_large_image' : 'summary',
                'twitter:title' => $ogTitle,
                'twitter:description' => $ogDescription,
                'twitter:image' => $image,
            ]),
            'json_ld' => array_values(array_filter([
                self::text($content?->value('schema_jsonld'), false),
                self::breadcrumbs($content),
            ])),
            'verification' => Seo::get('use_google_site_verification') ? self::text(Seo::get('google_site_verification')) : null,
            'trackers' => Seo::trackersEnabled() ? self::trackers() : null,
        ];
    }

    private static function pageTitle(mixed $content, string $title): string
    {
        $own = self::text($content?->value('seo_title'));

        if ($own !== null) {
            return $own;
        }

        $separator = ' '.(trim((string) Seo::get('title_separator', '|')) ?: '|').' ';
        $site = self::siteName();
        $collection = $content instanceof Entry ? $content->collection() : null;
        $row = $collection === null ? null : collect((array) Seo::get('change_page_title', []))
            ->first(fn ($row) => in_array($collection->handle(), (array) ($row['collection'] ?? []), true));
        $custom = is_array($row) ? (string) ($row['custom_text'] ?? '') : '';

        $parts = match (is_array($row) ? ($row['manipulate_title'] ?? null) : null) {
            'collection_title' => [$title, $collection?->title(), $site],
            'custom_text' => [$title, $custom, $site],
            'replace_title' => [$custom],
            default => [$title, $site],
        };

        return implode($separator, array_filter($parts, fn ($part) => is_string($part) && trim($part) !== ''));
    }

    private static function siteName(): string
    {
        return self::text(Seo::get('site_name')) ?? (string) config('app.name');
    }

    private static function robots(bool $indexes, bool $noindex, bool $nofollow): string
    {
        return match (true) {
            ! $indexes => 'noindex, nofollow',
            $noindex && $nofollow => 'noindex, nofollow',
            $nofollow => 'nofollow',
            $noindex => 'noindex',
            default => 'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1',
        };
    }

    private static function canonical(mixed $content, string $url): ?string
    {
        return match ($content?->value('seo_canonical_type')) {
            'current' => ($target = Values::first($content->augmentedValue('seo_canonical_current'))) && method_exists($target, 'absoluteUrl') ? $target->absoluteUrl() : $url,
            'external' => self::text($content->value('seo_canonical_external')) ?? $url,
            default => $url,
        };
    }

    /**
     * The entry in each site it is published in, itself included, for hreflang: only when the global asks, the entry
     * is its own canonical and the request is at its URL. Sites with an `identifier` attribute pair only with sites
     * carrying the same one, as Peak SEO did.
     *
     * @return list<array{hreflang: string, href: string}>
     */
    private static function alternates(mixed $content): array
    {
        if (! $content instanceof Entry || ! Seo::get('hreflang_auto') || ! in_array($content->value('seo_canonical_type'), [null, 'entry'], true)) {
            return [];
        }

        if (rtrim((string) $content->absoluteUrl(), '/') !== rtrim(url()->current(), '/')) {
            return [];
        }

        $identifier = Site::current()->attribute('identifier');
        $origin = $content->root();

        return Site::all()
            ->filter(fn ($site) => $site->attribute('identifier') === $identifier)
            ->map(fn ($site) => $origin->in($site->handle()))
            ->filter(fn ($entry) => $entry !== null && $entry->published() && $entry->url() !== null && ! Seo::noindex($entry))
            ->map(fn (Entry $entry) => ['hreflang' => str_replace('_', '-', (string) $entry->site()->locale()), 'href' => (string) $entry->absoluteUrl()])
            ->values()
            ->all();
    }

    private static function breadcrumbs(mixed $content): ?string
    {
        if (! Seo::get('breadcrumbs') || $content === null || trim((string) request()->path(), '/') === '') {
            return null;
        }

        try {
            $crumbs = collect(Statamic::tag('nav:breadcrumbs')->fetch());
        } catch (Throwable) {
            return null;
        }

        if ($crumbs->isEmpty()) {
            return null;
        }

        return json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $crumbs->values()->map(fn ($crumb, int $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => self::text(Values::unwrap(data_get($crumb, 'title'))),
                'item' => (string) Values::unwrap(data_get($crumb, 'permalink')),
            ])->all(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array{type: string, id: string, anonymize_ip: bool}|null */
    private static function trackers(): ?array
    {
        $type = (string) Seo::get('tracker_type', 'none');
        $id = self::text(Seo::get($type === 'gtm' ? 'google_tag_manager' : 'google_analytics'));

        if (! in_array($type, ['gtag', 'gtm'], true) || $id === null) {
            return null;
        }

        return ['type' => $type, 'id' => $id, 'anonymize_ip' => (bool) Seo::get('anonymize_ip', true)];
    }

    private static function cascade(string $key): mixed
    {
        try {
            return Values::unwrap(Cascade::instance()->get($key));
        } catch (Throwable) {
            return null;
        }
    }

    private static function text(mixed $value, bool $plain = true): ?string
    {
        $value = Values::unwrap($value);

        if (! is_scalar($value)) {
            return null;
        }

        $text = trim($plain ? strip_tags((string) $value) : (string) $value);

        return $text === '' ? null : $text;
    }
}
