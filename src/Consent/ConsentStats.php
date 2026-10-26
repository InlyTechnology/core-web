<?php

namespace Inly\CoreWeb\Consent;

use Illuminate\Support\Facades\DB;

/**
 * What visitors chose over a window of the consent log: how many choices,
 * how each was made (accept all, reject all, custom) and the share that
 * allowed each optional category. Counts choices, not visitors: one who
 * changes their mind is counted for each choice.
 */
class ConsentStats
{
    /** @return array{total: int, actions: array<string, int>, categories: array<string, int>} */
    public function since(\DateTimeInterface $from, ?string $site = null): array
    {
        $actions = array_fill_keys(Consent::ACTIONS, 0);
        $categories = array_fill_keys(array_values(array_diff(ConsentVendors::CATEGORIES, ['necessary'])), 0);
        $total = 0;

        $rows = DB::connection(config('core-web.consent.log.connection'))
            ->table((string) config('core-web.consent.log.table', 'core_web_consents'))
            ->where('created_at', '>=', $from)
            ->when($site !== null, fn ($query) => $query->where('site', $site))
            ->select(['id', 'action', 'categories'])
            ->lazyById(1000);

        foreach ($rows as $row) {
            $total++;

            if (array_key_exists($row->action, $actions)) {
                $actions[$row->action]++;
            }

            foreach ((array) json_decode((string) $row->categories, true) as $category) {
                if (is_string($category) && array_key_exists($category, $categories)) {
                    $categories[$category]++;
                }
            }
        }

        return ['total' => $total, 'actions' => $actions, 'categories' => $categories];
    }
}
