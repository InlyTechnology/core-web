<?php

namespace Inly\CoreWeb\Buttons;

use Illuminate\Support\Facades\Log;
use Inly\CoreWeb\Seo\Seo;
use Inly\CoreWeb\Snapshots\SnapshotRegistry;
use Inly\CoreWeb\Snapshots\SnapshotStore;
use Inly\CoreWeb\Support\Links;
use Inly\CoreWeb\Support\Values;
use Statamic\Contracts\Assets\Asset;
use Statamic\Contracts\Entries\Entry;
use Statamic\Dictionaries\Item;
use Statamic\Facades\Asset as AssetFacade;
use Statamic\Facades\Dictionary;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Icon as Icons;
use Statamic\Facades\Site;
use Statamic\Fields\Value;
use Throwable;

/**
 * The `core-web::button` fieldset as the site configures it (core-web.buttons),
 * and a row of it resolved to what a button renders: its address, label,
 * style and link attributes. The look stays the site's own component.
 */
final class Button
{
    /** The link types the fieldset knows, in the order the control panel offers them. */
    public const LINK_TYPES = ['entry' => 'Entry', 'url' => 'URL', 'email' => 'Email', 'tel' => 'Phone', 'sms' => 'SMS', 'asset' => 'Asset'];

    /** Handles of the fieldset's own fields, which a Core link type may not take. */
    /** @var array{0: string, 1: array<string, array{display: string, snapshot: ?string, dictionary: ?string}>}|null the last config read, so a page of buttons warns once */
    private static ?array $coreLinks = null;

    private const ICON_SET = 'core-web-buttons';

    private const RESERVED = ['label', 'show_controls', 'button_type', 'variant', 'style', 'link_type', 'entry', 'query_string', 'url', 'email', 'tel', 'sms', 'asset', 'icon_left', 'icon_right', 'target_blank', 'attr_title', 'attr_aria', 'tracker_event'];

    /** @return array<string, string> variant key => label */
    public static function variants(): array
    {
        $variants = array_filter((array) config('core-web.buttons.variants'), fn ($label, $key) => is_string($key) && $key !== '' && is_string($label), ARRAY_FILTER_USE_BOTH);

        return $variants === [] ? ['primary' => 'Primary'] : $variants;
    }

    /**
     * The Core link types: each a link to a page the site builds from Core,
     * picked from a snapshot that declares pages, or from a Dictionary of the
     * site's own whose items carry the page's `url`.
     *
     * @return array<string, array{display: string, snapshot: ?string, dictionary: ?string}>
     */
    public static function coreLinks(): array
    {
        $config = (array) config('core-web.buttons.core_links');
        $snapshots = (bool) config('core-web.features.snapshots');
        $memo = serialize([$config, $snapshots]);

        if (self::$coreLinks !== null && self::$coreLinks[0] === $memo) {
            return self::$coreLinks[1];
        }

        $links = [];

        foreach ($config as $key => $link) {
            $snapshot = is_array($link) && is_string($link['snapshot'] ?? null) && $link['snapshot'] !== '' ? $link['snapshot'] : null;
            $dictionary = is_array($link) && is_string($link['dictionary'] ?? null) && $link['dictionary'] !== '' ? $link['dictionary'] : null;

            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1 || in_array($key, self::RESERVED, true) || ($snapshot === null) === ($dictionary === null)) {
                Log::warning('core-web.buttons.core_links: each key is a free field handle naming one snapshot or one dictionary', ['key' => $key]);

                continue;
            }

            if ($snapshot !== null && ! $snapshots) {
                Log::warning('core-web.buttons.core_links: a snapshot link needs the snapshots feature on', ['key' => $key]);

                continue;
            }

            $links[$key] = [
                'display' => is_string($link['display'] ?? null) ? $link['display'] : ucfirst(str_replace('_', ' ', $key)),
                'snapshot' => $snapshot,
                'dictionary' => $dictionary,
            ];
        }

        self::$coreLinks = [$memo, $links];

        return $links;
    }

    /** @return array<string, string> link type => label, Entry first, then the Core links, then the rest */
    public static function linkTypes(): array
    {
        $enabled = array_values(array_intersect(array_keys(self::LINK_TYPES), (array) config('core-web.buttons.link_types')));
        $types = in_array('entry', $enabled, true) ? ['entry' => self::LINK_TYPES['entry']] : [];

        foreach (self::coreLinks() as $key => $link) {
            $types[$key] = $link['display'];
        }

        foreach ($enabled as $type) {
            $types[$type] ??= self::LINK_TYPES[$type];
        }

        return $types === [] ? ['url' => self::LINK_TYPES['url']] : $types;
    }

    /**
     * The `core-web::button` fieldset's contents for the site's config.
     *
     * @return array{title: string, fields: list<array{handle: string, field: array<string, mixed>}>}
     */
    public static function fieldset(): array
    {
        $advanced = ['show_controls' => 'equals true'];
        $inline = (bool) config('core-web.buttons.inline');
        $variants = self::variants();
        $types = self::linkTypes();
        $styled = count($variants) > 1;
        $fields = [
            self::field('label', ['type' => 'text', 'display' => 'Label', 'localizable' => true, 'width' => match (true) {
                $inline && $styled => 50,
                $inline || $styled => 66,
                default => 100,
            }, 'antlers' => false]),
        ];

        if ($inline) {
            $fields[] = self::field('button_type', ['type' => 'button_group', 'display' => 'Type', 'options' => ['button' => 'Button', 'inline' => 'Text link'], 'default' => 'button', 'width' => $styled ? 25 : 33]);
        }

        if ($styled) {
            $fields[] = self::field('variant', array_filter(['type' => 'button_group', 'display' => 'Style', 'options' => $variants, 'default' => array_key_first($variants), 'width' => $inline ? 25 : 33, 'if' => $inline ? ['button_type' => 'equals button'] : null]));
        }

        $core = self::coreLinks();
        $fields[] = self::field('link_type', array_filter([
            'type' => 'button_group',
            'display' => 'Link to',
            'instructions' => $core === [] ? 'Entry for a page on this site. URL only for another website.' : 'Entry for a page in the CMS, '.implode(', ', array_column($core, 'display')).' for a page built from Core. URL only for another website.',
            'ai_instructions' => 'A page on this website is never a URL: `entry` with the page\'s Statamic id'.($core === [] ? '' : ', or '.implode(', ', array_map(fn (string $key) => "`{$key}`", array_keys($core))).' with the pick of a page built from Core').'. `url` only for another website.',
            'options' => $types,
            'default' => array_key_first($types),
            'localizable' => true,
        ]));

        if (isset($types['entry'])) {
            $fields[] = self::field('entry', array_filter([
                'type' => 'entries',
                'display' => 'Entry',
                'max_items' => 1,
                'localizable' => true,
                'select_across_sites' => (bool) config('core-web.buttons.entries_across_sites') ?: null,
                'validate' => ['required_if:{this}.link_type,entry'],
                'width' => 66,
                'if' => ['link_type' => 'equals entry'],
            ]));
            $fields[] = self::field('query_string', ['type' => 'text', 'display' => 'Suffix', 'placeholder' => '?filter=x or #contact', 'localizable' => true, 'width' => 33, 'if' => ['link_type' => 'equals entry']]);
        }

        foreach ($core as $key => $link) {
            $fields[] = self::field($key, [
                'type' => 'dictionary',
                'display' => $link['display'],
                'dictionary' => $link['snapshot'] === null ? $link['dictionary'] : ['type' => SnapshotPagesDictionary::HANDLE, 'snapshot' => $link['snapshot']],
                'max_items' => 1,
                'localizable' => true,
                'validate' => ["required_if:{this}.link_type,{$key}"],
                'if' => ['link_type' => "equals {$key}"],
            ]);
        }

        $inputs = ['url' => ['URL', 'url', 'Another website\'s address, starting with https://.'], 'email' => ['Email', 'email', null], 'tel' => ['Phone', 'tel', null], 'sms' => ['SMS', 'tel', 'The number a text message goes to.']];

        foreach ($inputs as $type => [$display, $input, $instructions]) {
            if (isset($types[$type])) {
                $fields[] = self::field($type, array_filter(['type' => 'text', 'display' => $display, 'input_type' => $input, 'instructions' => $instructions, 'localizable' => true, 'validate' => $type === 'url' ? ['nullable', 'regex:~^(https?://|mailto:|tel:|/|#)~i'] : null, 'if' => ['link_type' => "equals {$type}"]]));
            }
        }

        if (isset($types['asset'])) {
            $fields[] = self::field('asset', ['type' => 'assets', 'display' => 'Asset', 'container' => (string) config('core-web.buttons.asset_container', 'assets'), 'max_files' => 1, 'localizable' => true, 'if' => ['link_type' => 'equals asset']]);
        }

        $fields[] = self::field('show_controls', ['type' => 'revealer', 'mode' => 'button', 'display' => 'Advanced options', 'input_label' => 'Advanced options', 'hide_display' => true]);

        if (config('core-web.buttons.icons', true)) {
            $set = self::iconSet();
            $icon = $set === null
                ? ['type' => 'text', 'instructions' => 'An icon name from the site\'s icon set, e.g. `arrow-right`.']
                : ['type' => 'icon', 'set' => $set, 'mode' => 'compact'];
            $fields[] = self::field('icon_left', ['display' => 'Icon before'] + $icon + ['width' => 25, 'if' => $advanced]);
            $fields[] = self::field('icon_right', ['display' => 'Icon after'] + $icon + ['width' => 25, 'if' => $advanced]);
        }

        $fields[] = self::field('target_blank', ['type' => 'toggle', 'display' => 'Open in a new tab', 'width' => 25, 'if' => $advanced]);
        $fields[] = self::field('tracker_event', ['type' => 'text', 'display' => 'Tracker event', 'placeholder' => 'booking_click', 'validate' => ['nullable', 'regex:/^[A-Za-z0-9_-]+$/'], 'ai_fillable' => false, 'width' => 25, 'if' => $advanced]);
        $fields[] = self::field('attr_title', ['type' => 'text', 'display' => 'Title', 'instructions' => 'Shown on hover.', 'width' => 50, 'if' => $advanced]);
        $fields[] = self::field('attr_aria', ['type' => 'text', 'display' => 'Screen reader label', 'instructions' => 'Only when the label does not say where it goes.', 'width' => 50, 'if' => $advanced]);

        return ['title' => 'Button', 'fields' => $fields];
    }

    /**
     * A row of the button fieldset (a grid row, a group, a set's values) as
     * it renders, or null when it renders nothing: no label, or a link that
     * resolves to nothing (an entry since deleted, a Core page no longer
     * published).
     *
     * @return array{href: string, label: string, variant: string, inline: bool, icon_left: ?string, icon_right: ?string, link_type: string, attrs: array<string, string>}|null
     */
    public static function resolve(mixed $button): ?array
    {
        $row = Values::unwrap($button);
        $variants = self::variants();
        $read = fn (string $key): mixed => Values::unwrap(is_array($row) || $row instanceof \ArrayAccess ? ($row[$key] ?? null) : null);
        $text = fn (string $key): ?string => is_scalar($value = $read($key)) && trim((string) $value) !== '' ? trim((string) $value) : null;
        $types = self::linkTypes();
        $type = $text('link_type') ?? array_key_first($types);
        $variant = $text('variant') ?? $text('style');

        $resolved = [
            'href' => '',
            'label' => $text('label'),
            'variant' => $variant !== null && isset($variants[$variant]) ? $variant : array_key_first($variants),
            'inline' => (bool) config('core-web.buttons.inline') && $text('button_type') === 'inline',
            'icon_left' => self::icon($row, 'icon_left'),
            'icon_right' => self::icon($row, 'icon_right'),
            'link_type' => $type,
            'attrs' => [],
        ];

        if ($row === null || $row === [] || $resolved['label'] === null) {
            return null;
        }

        $href = match (true) {
            $type === 'entry' => Links::withSuffix(self::entryUrl($read('entry')), $read('query_string')),
            $type === 'url' => (string) ($text('url') ?? ''),
            $type === 'email' => ($email = $text('email')) === null ? '' : 'mailto:'.$email,
            $type === 'tel' => self::number('tel', $text('tel')),
            $type === 'sms' => self::number('sms', $text('sms')),
            $type === 'asset' => self::assetUrl($read('asset')),
            isset(self::coreLinks()[$type]) => self::coreLinkUrl($type, $read($type)),
            default => '',
        };
        $resolved['href'] = self::safeHref($href);

        if ($resolved['href'] === '') {
            return null;
        }

        $attrs = [];

        if ($read('target_blank')) {
            $attrs['target'] = '_blank';
            $attrs['rel'] = 'noopener';
        }

        if ($type === 'asset') {
            $attrs['download'] = '';
        }

        if ($title = $text('attr_title')) {
            $attrs['title'] = $title;
        }

        if ($aria = $text('attr_aria')) {
            $attrs['aria-label'] = $aria;
        }

        if (($event = self::trackerEvent($text('tracker_event'))) !== null) {
            $attrs['onclick'] = $event;
        }

        $resolved['attrs'] = $attrs;

        return $resolved;
    }

    /** The address of the page a Core link picks: its snapshot document's page here, or its Dictionary item's `url`. */
    public static function coreLinkUrl(string $type, mixed $pick): string
    {
        $link = self::coreLinks()[$type] ?? null;
        $pick = Values::first($pick);
        $key = $pick instanceof Item ? $pick->value() : $pick;

        if ($link === null || ! is_scalar($key) || trim((string) $key) === '') {
            return '';
        }

        try {
            if ($link['snapshot'] !== null) {
                return self::snapshotPageUrl($link['snapshot'], trim((string) $key));
            }

            $url = ($pick instanceof Item ? $pick : Dictionary::find($link['dictionary'])?->get(trim((string) $key)))?->data()['url'] ?? null;

            return is_string($url) ? $url : '';
        } catch (Throwable $e) {
            // A Core page that cannot be read now (Core down, a dictionary renamed) is a missing button, not a failed page.
            Log::warning('core-web button: a Core link did not resolve', ['type' => $type, 'exception' => $e::class]);

            return '';
        }
    }

    /** A snapshot document's page on the current site as a path from the host's root, empty when it has none here. */
    public static function snapshotPageUrl(string $snapshot, string $id): string
    {
        $registry = app(SnapshotRegistry::class);

        if (! $registry->has($snapshot) || ! $registry->get($snapshot)->hasPages()) {
            return '';
        }

        $snapshot = $registry->get($snapshot);
        $site = Site::current();
        $partition = $snapshot->partitionFor($site->handle());

        if ($partition === false) {
            return '';
        }

        $document = app(SnapshotStore::class)->read($snapshot, $partition)->documents[$id] ?? null;
        $path = is_array($document) ? $snapshot->pagePath($document, $site->handle()) : null;

        if ($path === null) {
            return '';
        }

        $prefix = rtrim((string) parse_url($site->absoluteUrl(), PHP_URL_PATH), '/');

        return $path === '/' && $prefix !== '' ? $prefix : $prefix.$path;
    }

    /** An entry picked on another site keeps that site's host when it has one of its own. */
    private static function entryUrl(mixed $value): string
    {
        $entry = Values::first($value);

        if (is_string($entry) && $entry !== '') {
            $entry = Entries::find($entry);
        }

        $current = Site::current();

        if ($entry instanceof Entry && ! config('core-web.buttons.entries_across_sites')) {
            $entry = $entry->in($current->handle()) ?? $entry;
        }

        if (! $entry instanceof Entry || ! $entry->published()) {
            return '';
        }

        $sameHost = $entry->locale() === $current->handle()
            || parse_url((string) $entry->site()?->absoluteUrl(), PHP_URL_HOST) === parse_url($current->absoluteUrl(), PHP_URL_HOST);

        return (string) ($sameHost ? $entry->url() : $entry->absoluteUrl());
    }

    /** A grid row inside a set holds the asset's raw path, not the Asset; a full URL passes through. */
    private static function assetUrl(mixed $value): string
    {
        $asset = Values::first($value);

        if (is_string($asset) && preg_match('~^https?://~', $asset) === 1) {
            return $asset;
        }

        if (is_string($asset) && $asset !== '') {
            $asset = AssetFacade::find(str_contains($asset, '::') ? $asset : config('core-web.buttons.asset_container', 'assets').'::'.$asset);
        }

        return $asset instanceof Asset ? (string) $asset->url() : '';
    }

    /** A phone number as a `tel:` or `sms:` address, empty when it holds no digits. */
    private static function number(string $scheme, ?string $number): string
    {
        $number = preg_replace('/[^0-9+]/', '', (string) $number);

        return preg_match('/[0-9]/', (string) $number) === 1 ? "{$scheme}:{$number}" : '';
    }

    /**
     * Only web, mail and phone addresses, paths and anchors: a URL field is
     * also written by Core AI drafts and content pushes, and a `javascript:`
     * address would run on click.
     */
    private static function safeHref(string $href): string
    {
        $href = trim($href);

        if ($href === '' || preg_match('~^(/|#|\?)~', $href) === 1 && ! str_starts_with($href, '//')) {
            return $href;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);

        if (str_starts_with($href, '//') || in_array(is_string($scheme) ? strtolower($scheme) : null, ['http', 'https', 'mailto', 'tel', 'sms'], true)) {
            return $href;
        }

        // No scheme and no leading slash: a relative path such as `kontakt`, unless a colon makes it one a browser would run.
        return $scheme === null && ! str_contains(strtok($href, '/?#') ?: '', ':') ? $href : '';
    }

    /**
     * The Statamic icon set the icon fields pick from: `buttons.icon_set`, a
     * folder of SVGs registered here, or the name of a set the site
     * registered itself. Null keeps them text fields.
     */
    public static function iconSet(): ?string
    {
        $set = config('core-web.buttons.icon_set');

        if (! is_string($set) || $set === '') {
            return null;
        }

        if (! is_dir($set)) {
            return Icons::sets()->has($set) ? $set : null;
        }

        if (! Icons::sets()->has(self::ICON_SET)) {
            Icons::register(self::ICON_SET, $set);
        }

        return self::ICON_SET;
    }

    /**
     * An icon field's name, never its augmented SVG; `fa-arrow-right` and
     * `arrow-right` name the same icon.
     */
    private static function icon(mixed $row, string $key): ?string
    {
        $value = is_array($row) || $row instanceof \ArrayAccess ? ($row[$key] ?? null) : null;
        $value = $value instanceof Value ? $value->raw() : Values::unwrap($value);

        return is_string($value) && trim($value) !== '' ? (preg_replace('/^fa-/', '', trim($value)) ?: null) : null;
    }

    /**
     * The click handler that sends an event to the site's tracker while its
     * tags render here. Guarded, since the consent banner may still hold the
     * tag back; the name is kept to the characters GA4 allows, so an editor
     * cannot write script into it.
     */
    private static function trackerEvent(?string $event): ?string
    {
        $event = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $event);

        if ($event === '' || ! Seo::trackersEnabled()) {
            return null;
        }

        if (Seo::get('use_fathom')) {
            return "window.fathom&&fathom.trackEvent('{$event}')";
        }

        return match ((string) Seo::get('tracker_type', 'none')) {
            'gtm', 'sgtm' => "(window.dataLayer=window.dataLayer||[]).push({'event':'{$event}'})",
            'gtag' => "window.gtag&&gtag('event','{$event}')",
            default => null,
        };
    }

    /** @param  array<string, mixed>  $field */
    private static function field(string $handle, array $field): array
    {
        return ['handle' => $handle, 'field' => $field];
    }
}
