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
        $site = is_string($site) && $site !== '' ? $site : null;
        $consent = app(ConsentStats::class);

        if (! $consent->available()) {
            return '<ui-widget><div class="px-4 py-3 text-sm text-red-600" v-pre>'.e(__('The consent log has no table yet. Publish and run its migration (core-web-consent-migration).')).'</div></ui-widget>';
        }

        $stats = $consent->since(now()->subDays($days), $site);
        $percent = fn (int $count): int => $stats['total'] === 0 ? 0 : (int) round($count / $stats['total'] * 100);
        $shares = self::shares([$stats['actions']['accept_all'], $stats['actions']['custom'], $stats['actions']['reject_all']]);
        $asked = array_values(array_diff(array_keys(ConsentVendors::byCategory()), ['necessary']));

        return view('core-web::widgets.consent-stats', [
            'title' => __((string) $this->config('title', 'Cookie consent')),
            'period' => __('Last :days days', ['days' => $days]),
            'total' => $stats['total'],
            'actions' => [
                ['label' => __('Accepted all'), 'percent' => $shares[0], 'color' => 'bg-green-500'],
                ['label' => __('Chose some'), 'percent' => $shares[1], 'color' => 'bg-amber-500'],
                ['label' => __('Rejected all'), 'percent' => $shares[2], 'color' => 'bg-red-500'],
            ],
            'categories' => array_map(fn (string $category): array => [
                'label' => __(ucfirst($category)),
                'percent' => $percent($stats['categories'][$category] ?? 0),
            ], array_values(array_intersect(array_keys($stats['categories']), $asked))),
            'multisite' => Site::hasMultiple() && $site === null,
        ]);
    }

    /**
     * Whole percentages of the counts that add up to 100 (largest remainder), so the stacked bar fills exactly.
     *
     * @param  list<int>  $counts
     * @return list<int>
     */
    public static function shares(array $counts): array
    {
        $total = array_sum($counts);

        if ($total === 0) {
            return array_fill(0, count($counts), 0);
        }

        $exact = array_map(fn (int $count): float => $count / $total * 100, $counts);
        $shares = array_map(fn (float $share): int => (int) floor($share), $exact);
        $remainders = array_map(fn (float $share, int $floor): float => $share - $floor, $exact, $shares);
        arsort($remainders);

        foreach (array_slice(array_keys($remainders), 0, 100 - array_sum($shares)) as $index) {
            $shares[$index]++;
        }

        return $shares;
    }
}
