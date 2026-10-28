<?php

namespace Inly\CoreWeb\Seo;

use Illuminate\Support\Str;
use Inly\CoreWeb\CoreWebServiceProvider;
use Inly\CoreWeb\Media\MediaSource;
use Inly\CoreWeb\Media\UrlScheme;
use Inly\CoreWeb\Support\Assets;
use Inly\CoreWeb\Support\Values;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\URL;

/**
 * What a page shares as: og:image and the description, when the entry's own SEO fields are empty. The image is the
 * entry's og_image, the field core-web.seo.social.collections names, the hero block's, then the global's og_image;
 * the description the global's collection_defaults row, then the named field.
 *
 * Asset meta is read only through Assets::meta(): this runs on every page, and a bare read on a cache miss copies
 * the file out of the bucket outside the disk's `throw => false`. That is also why no og:image:width is emitted.
 */
final class SocialCard
{
    /** @return array{type: string, url: string, image_url: ?string, image_alt: ?string, description: ?string} */
    public static function for(mixed $content): array
    {
        $entry = Seo::content($content);
        $meta = $entry instanceof Entry ? self::entryMeta($entry) : [];
        $image = $meta['image'] ?? null;
        $imageUrl = self::imageUrl($image);

        return [
            'type' => $meta['type'] ?? 'website',
            'url' => url()->current(),
            'image_url' => $imageUrl ?? self::imageUrl(Seo::augmented('og_image')) ?? self::imageUrl(Seo::augmented('og_image', Site::default()->handle())),
            'image_alt' => $imageUrl === null ? null : self::text(Assets::meta($image, 'alt')),
            'description' => self::truncate($meta['description'] ?? null),
        ];
    }

    /** An image's URL as a card: the CDN's crop to the card's size around the focal point, or the file itself, absolute. */
    public static function imageUrl(mixed $image): ?string
    {
        $source = MediaSource::from($image);

        if ($source === null || $source->isVideo()) {
            return null;
        }

        $scheme = CoreWebServiceProvider::enabled('media') ? app(UrlScheme::class) : null;

        if ($scheme === null || ! $scheme->serves($source->url) || $source->isSvg()) {
            return URL::makeAbsolute($source->url);
        }

        $size = (array) config('core-web.seo.social.image', []);
        $focus = $source->focus;

        return URL::makeAbsolute($scheme->image($source->url, array_filter([
            'width' => $size['width'] ?? 1200,
            'height' => $size['height'] ?? 630,
            'fit' => 'cover',
            'format' => $size['format'] ?? 'jpeg',
            'gravity' => $focus ? self::fraction($focus[0]).'x'.self::fraction($focus[1]) : null,
        ])));
    }

    /** @return array{image: mixed, description: ?string, type: string} */
    private static function entryMeta(Entry $entry): array
    {
        $collection = (string) $entry->collectionHandle();
        $map = (array) config("core-web.seo.social.collections.{$collection}", []);
        $named = Values::first($entry->augmentedValue((string) ($map['image'] ?? 'image')));

        return [
            'type' => (string) ($map['type'] ?? 'website'),
            'image' => Values::first($entry->augmentedValue('og_image')) ?: $named ?: self::heroImage($entry),
            'description' => self::gridDefault($entry, $collection) ?? (isset($map['description']) ? self::text($entry->value($map['description'])) : null),
        ];
    }

    private static function heroImage(Entry $entry): mixed
    {
        $hero = (array) config('core-web.seo.social.hero', []);
        $set = self::firstSet($entry);

        if (empty($hero['set']) || ! is_array($set) || ($set['type'] ?? null) !== $hero['set']) {
            return null;
        }

        return Values::first($set[$hero['image_field'] ?? 'image'] ?? null);
    }

    /** @return array<string, mixed>|null */
    private static function firstSet(Entry $entry): ?array
    {
        $field = (string) data_get(config('core-web.seo.social.hero'), 'field', 'page_builder');
        $sets = Values::unwrap($entry->augmentedValue($field));
        $first = is_iterable($sets) ? collect($sets)->first() : null;

        if (is_object($first) && method_exists($first, 'all')) {
            $first = $first->all();
        }

        return is_array($first) ? array_map(fn ($value) => Values::unwrap($value), $first) : null;
    }

    /** The global's collection_defaults row for the collection: a field, a custom text, or the first block's text. */
    private static function gridDefault(Entry $entry, string $collection): ?string
    {
        $row = collect((array) Seo::get('collection_defaults', []))
            ->first(fn ($row) => in_array($collection, (array) ($row['collection'] ?? []), true));

        if (! is_array($row)) {
            return null;
        }

        return match ($row['fallback'] ?? null) {
            'field' => self::text($entry->value((string) ($row['field_handle'] ?? ''))),
            'custom_text' => self::text($row['custom_text'] ?? null),
            'page_builder' => self::text(data_get($entry->value((string) data_get(config('core-web.seo.social.hero'), 'field', 'page_builder')), '0.text')),
            default => null,
        };
    }

    /** Plain text from a string or a stored Bard tree, its top-level paragraphs only: a heading reads badly at the front of a snippet. */
    public static function text(mixed $value): ?string
    {
        $value = Values::unwrap($value);

        if (is_iterable($value)) {
            $value = collect($value)
                ->filter(fn ($node) => is_array($node) && ($node['type'] ?? null) === 'paragraph')
                ->flatMap(fn (array $node) => collect($node['content'] ?? [])->pluck('text'))
                ->filter()
                ->implode(' ');
        }

        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value)));

        return $text === '' ? null : $text;
    }

    private static function truncate(?string $text): ?string
    {
        return $text === null ? null : Str::limit($text, (int) config('core-web.seo.social.description_limit', 157));
    }

    private static function fraction(float $percent): float
    {
        return min(1, max(0, round($percent / 100, 4)));
    }
}
