<?php

namespace Inly\CoreWeb\Widgets;

use Illuminate\Contracts\View\View;
use Inly\CoreWeb\Consent\ConsentStats;
use Inly\CoreWeb\Consent\ConsentVendors;
use Statamic\Facades\Site;
use Statamic\Widgets\Widget;

/**
 * The `consent_stats` dashboard widget: over the last `days` of the consent
 * log, how visitors answered the banner and the share that allowed each
 * category the site asks for (ConsentStats).
 *
 *     ['type' => 'consent_stats', 'days' => 30, 'width' => 50]
 */
class ConsentStatsWidget extends Widget
{
    protected static $handle = 'consent_stats';

    public const MAX_DAYS = 365;

    public function html(): View|string
    {
        $days = (int) $this->config('days', 30);
        $days = $days < 1 ? 30 : min(self::MAX_DAYS, $days);
        $site = $this->config('site');
        $stats = app(ConsentStats::class)->since(now()->subDays($days), is_string($site) && $site !== '' ? $site : null);
        $percent = fn (int $count): int => $stats['total'] === 0 ? 0 : (int) round($count / $stats['total'] * 100);
        $asked = array_values(array_diff(array_keys(ConsentVendors::byCategory()), ['necessary']));

        return view('core-web::widgets.consent-stats', [
            'title' => __((string) $this->config('title', 'Cookie consent')),
            'period' => __('Last :days days', ['days' => $days]),
            'total' => $stats['total'],
            'actions' => [
                ['label' => __('Accepted all'), 'percent' => $percent($stats['actions']['accept_all']), 'color' => 'bg-green-500'],
                ['label' => __('Chose some'), 'percent' => $percent($stats['actions']['custom']), 'color' => 'bg-amber-500'],
                ['label' => __('Rejected all'), 'percent' => $percent($stats['actions']['reject_all']), 'color' => 'bg-red-500'],
            ],
            'categories' => array_map(fn (string $category): array => [
                'label' => __(ucfirst($category)),
                'percent' => $percent($stats['categories'][$category] ?? 0),
            ], array_values(array_intersect(array_keys($stats['categories']), $asked))),
            'multisite' => Site::multiEnabled() && ! (is_string($site) && $site !== ''),
        ]);
    }
}
