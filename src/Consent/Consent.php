<?php

namespace Inly\CoreWeb\Consent;

use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;

/** What the banner, the declaration and the script in the page's head share. */
class Consent
{
    public const ACTIONS = ['accept_all', 'reject_all', 'custom'];

    /**
     * The settings core-consent.js reads from window.coreConsent; the same for every visitor, so a cached page holds it.
     *
     * @return array<string, mixed>
     */
    public static function script(): array
    {
        return [
            'cookie' => (string) self::setting('cookie', 'core_consent'),
            'version' => (int) self::setting('policy_version', 1),
            'renewAfterDays' => max(1, (int) self::setting('renew_after_days', 365)),
            'categories' => array_values(array_diff(array_keys(ConsentVendors::byCategory()), ['necessary'])),
            'cookies' => ConsentVendors::cookieNames(),
            'consentMode' => (bool) self::setting('google.consent_mode', true),
            'site' => Site::current()->handle(),
            'log' => self::logs() ? route('core-web.consent.store', [], false) : null,
        ];
    }

    /**
     * A consent setting for the current site: core-web.consent.sites.<handle>.<key> where the site sets it, else
     * core-web.consent.<key>. A multisite install's domains share one banner but may declare their own vendors.
     */
    public static function setting(string $key, mixed $default = null): mixed
    {
        $site = (array) config('core-web.consent.sites.'.Site::current()->handle(), []);
        $base = config("core-web.consent.{$key}", $default);
        $own = data_get($site, $key);

        if ($own === null) {
            return $base;
        }

        return is_array($own) && is_array($base) && ! array_is_list($own) && $key !== 'vendors' ? array_replace($base, $own) : $own;
    }

    public static function logs(): bool
    {
        return (bool) config('core-web.consent.log.enabled', true);
    }

    /** Google's tags wait for statistics consent, unless the site chose advanced mode. */
    public static function holdsGoogleTags(): bool
    {
        return self::setting('google.mode', 'basic') !== 'advanced';
    }

    /** The branding overrides as CSS custom properties on the banner's root; Inly Core's look is core-consent.css's defaults. */
    public static function brandingStyle(): string
    {
        $branding = (array) self::setting('branding', []);
        $properties = [
            '--core-consent-accent' => $branding['accent'] ?? null,
            '--core-consent-accent-foreground' => $branding['accent_foreground'] ?? null,
            '--core-consent-background' => $branding['background'] ?? null,
            '--core-consent-foreground' => $branding['foreground'] ?? null,
            '--core-consent-font' => $branding['font'] ?? null,
        ];

        return collect($properties)
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '' && preg_match('/[;{}<>"\\\\]/', $value) !== 1)
            ->map(fn (string $value, string $property): string => "{$property}: {$value}")
            ->implode('; ');
    }

    /** Whether the site asks for anything: with only necessary cookies there is no choice to make, so no banner. */
    public static function asks(): bool
    {
        return array_diff(array_keys(ConsentVendors::byCategory()), ['necessary']) !== [];
    }

    /**
     * The banner's title, text and cookie page as an editor set them on the global set `consent.global` names
     * (the fields of the `core-web::consent` fieldset), each null where nothing is set.
     *
     * @return array{title: string|null, text: string|null, policy_url: string|null}
     */
    public static function editorial(): array
    {
        $handle = config('core-web.consent.global');
        $site = Site::current()->handle();
        $variables = is_string($handle) && $handle !== '' ? GlobalSet::find($handle)?->in($site) : null;
        $text = fn (string $key): ?string => is_string($value = $variables?->value($key)) && trim($value) !== '' ? trim($value) : null;
        $page = $variables?->value('consent_policy_page');
        $id = is_array($page) ? ($page[0] ?? null) : $page;
        $entry = is_string($id) && $id !== '' ? Entry::find($id) : null;
        $entry = $entry?->in($site);

        return [
            'title' => $text('consent_title'),
            'text' => $text('consent_text'),
            'policy_url' => $entry?->status() === 'published' ? $entry->url() : null,
        ];
    }

    /** Inly Core's mark, inline so the banner loads nothing more. */
    public static function mark(): string
    {
        return (string) file_get_contents(__DIR__.'/../../resources/svg/core.svg');
    }
}
