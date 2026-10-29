<?php

namespace Inly\CoreWeb\Consent;

use InvalidArgumentException;

/**
 * The vendors a site declares (core-web.consent.vendors), resolved against the
 * catalogue in resources/consent/vendors.json, which a non-PHP site's banner reads too. A cookie's lifetime is `days` (null for a session cookie) or
 * `minutes`; a name ending in `*` is a prefix (`_ga_*`). The lifetimes are the
 * vendors' documented defaults; a site whose setup differs overrides the entry.
 */
class ConsentVendors
{
    /** @var list<string> */
    public const CATEGORIES = ['necessary', 'preferences', 'statistics', 'marketing'];

    /** @var list<string> */
    public const SIGNALS = ['uet', 'clarity'];

    /**
     * @return array<string, array{name: string, category: string, policy_url?: string, signal?: string, cookies: list<array{name: string, purpose: string, days?: int|null, minutes?: int}>}>
     */
    public static function catalogue(): array
    {
        static $catalogue = null;

        return $catalogue ??= json_decode((string) file_get_contents(__DIR__.'/../../resources/consent/vendors.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The site's vendors, its own necessary cookies first.
     *
     * @return array<string, array{name: string, category: string, policy_url?: string, signal?: string, cookies: list<array{name: string, purpose: string, days?: int|null, minutes?: int}>}>
     */
    public static function declared(): array
    {
        $catalogue = self::catalogue();
        $vendors = ['site' => self::site()];

        foreach ((array) Consent::setting('vendors', []) as $key => $value) {
            [$key, $own] = is_int($key) ? [(string) $value, []] : [(string) $key, (array) $value];
            $vendor = array_replace($catalogue[$key] ?? [], $own);

            if (! isset($vendor['name'], $vendor['category']) || ! in_array($vendor['category'], self::CATEGORIES, true)) {
                throw new InvalidArgumentException("core-web.consent.vendors: [{$key}] is not in the catalogue, so it needs a name and a category (".implode(', ', self::CATEGORIES).').');
            }

            $vendors[$key] = $vendor + ['cookies' => []];
        }

        return $vendors;
    }

    /**
     * The categories the site asks for, necessary first, each with its vendors.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function byCategory(): array
    {
        $categories = array_fill_keys(self::CATEGORIES, []);

        foreach (self::declared() as $key => $vendor) {
            $categories[$vendor['category']][$key] = $vendor;
        }

        return array_filter($categories, fn (array $vendors, string $category): bool => $category === 'necessary' || $vendors !== [], ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The cookie names of each optional category, which the banner deletes when that category is withdrawn.
     *
     * @return array<string, list<string>>
     */
    public static function cookieNames(): array
    {
        $names = [];

        foreach (self::byCategory() as $category => $vendors) {
            if ($category === 'necessary') {
                continue;
            }

            $names[$category] = array_values(array_unique(array_merge(...array_map(
                fn (array $vendor): array => array_column($vendor['cookies'], 'name'),
                array_values($vendors),
            ))));
        }

        return $names;
    }

    /**
     * The consent APIs of the declared vendors (`signal` in the catalogue), which hear the visitor's choice beside
     * Consent Mode, so a tag holding no category of its own (one in Tag Manager) still reads it.
     *
     * @return list<string>
     */
    public static function signals(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (array $vendor): ?string => isset($vendor['signal']) && in_array($vendor['signal'], self::SIGNALS, true) ? $vendor['signal'] : null,
            array_values(self::declared()),
        ))));
    }

    /** @return array{name: string, category: string, cookies: list<array{name: string, purpose: string, days?: int|null}>} */
    private static function site(): array
    {
        return [
            'name' => (string) __('This website'),
            'category' => 'necessary',
            'cookies' => [
                ['name' => (string) Consent::setting('cookie', 'core_consent'), 'purpose' => 'Remembers your cookie choice.', 'days' => (int) Consent::setting('renew_after_days', 365)],
                ['name' => (string) config('session.cookie', 'laravel_session'), 'purpose' => 'Keeps your visit together, for forms and sign-in.', 'days' => null],
                ['name' => 'XSRF-TOKEN', 'purpose' => 'Protects forms against forgery.', 'days' => null],
            ],
        ];
    }

    /** A cookie's lifetime in words. */
    public static function lifetime(array $cookie): string
    {
        if (isset($cookie['minutes'])) {
            return (string) __(':count minutes', ['count' => $cookie['minutes']]);
        }

        $days = $cookie['days'] ?? null;

        return match (true) {
            $days === null => (string) __('Session'),
            $days === 1 => (string) __('1 day'),
            $days < 60 => (string) __(':count days', ['count' => $days]),
            $days < 365 => (string) __(':count months', ['count' => intdiv($days, 30)]),
            $days < 730 => (string) __('1 year'),
            default => (string) __(':count years', ['count' => intdiv($days, 365)]),
        };
    }
}
