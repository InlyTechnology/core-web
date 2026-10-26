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
    /** Whether the log's table exists: its migration is published and run by the site, so it may not yet. */
    public function available(): bool
    {
        return DB::connection(config('core-web.consent.log.connection'))->getSchemaBuilder()->hasTable($this->table());
    }

    /** @return array{total: int, actions: array<string, int>, categories: array<string, int>} */
    public function since(\DateTimeInterface $from, ?string $site = null): array
    {
        $actions = array_fill_keys(Consent::ACTIONS, 0);
        $categories = array_fill_keys(array_values(array_diff(ConsentVendors::CATEGORIES, ['necessary'])), 0);
        $query = fn () => DB::connection(config('core-web.consent.log.connection'))
            ->table($this->table())
            ->where('created_at', '>=', $from)
            ->when($site !== null, fn ($query) => $query->where('site', $site));

        foreach ($query()->selectRaw('action, count(*) as choices')->groupBy('action')->get() as $row) {
            if (array_key_exists($row->action, $actions)) {
                $actions[$row->action] = (int) $row->choices;
            }
        }

        $total = array_sum($actions);

        // A rejection allows nothing, so only the other choices are read for their categories.
        foreach ($query()->where('action', '!=', 'reject_all')->select(['id', 'categories'])->lazyById(1000) as $row) {
            foreach ((array) json_decode((string) $row->categories, true) as $category) {
                if (is_string($category) && array_key_exists($category, $categories)) {
                    $categories[$category]++;
                }
            }
        }

        return ['total' => $total, 'actions' => $actions, 'categories' => $categories];
    }

    private function table(): string
    {
        return (string) config('core-web.consent.log.table', 'core_web_consents');
    }
}
