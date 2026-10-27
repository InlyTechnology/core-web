<?php

namespace Inly\CoreWeb\Consent;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Site;

/** What a visitor saw when they chose: the banner's texts, categories, vendors and cookies, fingerprinted and kept once. */
class ConsentSnapshot
{
    /**
     * The banner as the current site and locale show it.
     *
     * @return array{site: string, locale: string, policy_version: int, texts: array<string, mixed>, categories: array<string, list<array<string, mixed>>>}
     */
    public static function content(): array
    {
        $categories = [];

        foreach (ConsentVendors::byCategory() as $category => $vendors) {
            foreach ($vendors as $key => $vendor) {
                $categories[$category][] = array_filter([
                    'key' => (string) $key,
                    'name' => (string) $vendor['name'],
                    'policy_url' => $vendor['policy_url'] ?? null,
                    'cookies' => array_map(fn (array $cookie): array => [
                        'name' => (string) $cookie['name'],
                        'purpose' => (string) __($cookie['purpose']),
                        'expiry' => ConsentVendors::lifetime($cookie),
                    ], $vendor['cookies']),
                ], fn (mixed $value): bool => $value !== null);
            }
        }

        return [
            'site' => Site::current()->handle(),
            'locale' => self::locale(),
            'policy_version' => (int) Consent::setting('policy_version', 1),
            'texts' => Consent::texts(),
            'categories' => $categories,
        ];
    }

    /** A hash of the whole content, so a log row names exactly the banner it was given on. */
    public static function fingerprint(array $content): string
    {
        return hash('sha256', json_encode(self::sorted($content), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * A short hash of what the visitor agrees to (the categories, vendors and cookie names), so changing those asks
     * every visitor again, while rewording a text does not.
     */
    public static function scope(): string
    {
        $scope = [];

        foreach (ConsentVendors::byCategory() as $category => $vendors) {
            foreach ($vendors as $key => $vendor) {
                $scope[$category][(string) $key] = array_column($vendor['cookies'], 'name');
            }
        }

        return substr(hash('sha256', json_encode(self::sorted($scope), JSON_THROW_ON_ERROR)), 0, 16);
    }

    public static function locale(): string
    {
        return (string) (Site::current()->locale() ?? app()->getLocale());
    }

    /** Keeps the content under its fingerprint, once; a fingerprint already kept is left as it is. */
    public static function store(array $content): string
    {
        $fingerprint = self::fingerprint($content);

        self::connection()->table(self::table())->insertOrIgnore([
            'fingerprint' => $fingerprint,
            'site' => $content['site'],
            'locale' => $content['locale'],
            'policy_version' => $content['policy_version'],
            'content' => json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        return $fingerprint;
    }

    /** @return array<string, mixed>|null */
    public static function find(string $fingerprint): ?array
    {
        $row = self::connection()->table(self::table())->where('fingerprint', $fingerprint)->first();

        return $row ? array_merge(json_decode((string) $row->content, true) ?: [], ['stored_at' => (string) $row->created_at]) : null;
    }

    public static function connection(): Connection
    {
        return DB::connection(config('core-web.consent.log.connection'));
    }

    public static function table(): string
    {
        return (string) config('core-web.consent.log.snapshot_table', 'core_web_consent_snapshots');
    }

    /** Lists keep their order, which the banner shows; keys are sorted so a reordered config hashes the same. */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
