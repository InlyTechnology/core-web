<?php

namespace Inly\CoreWeb\Consent;

/** The proof of consent the site can hand over: one visitor's choices with the banner each was made on, or the totals. */
class ConsentEvidence
{
    /**
     * Every choice recorded under a consent id, oldest first, and the snapshot of each banner they were made on.
     *
     * @return array{consent_id: string, exported_at: string, choices: list<array<string, mixed>>, banners: array<string, array<string, mixed>|null>}
     */
    public static function forId(string $id): array
    {
        $choices = ConsentSnapshot::connection()
            ->table((string) config('core-web.consent.log.table', 'core_web_consents'))
            ->where('consent_id', strtolower(trim($id)))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => [
                'chosen_at' => (string) $row->created_at,
                'site' => $row->site,
                'locale' => $row->locale ?? null,
                'action' => $row->action,
                'categories' => json_decode((string) $row->categories, true) ?: [],
                'policy_version' => (int) $row->policy_version,
                'fingerprint' => $row->fingerprint ?? null,
            ])
            ->all();

        $banners = [];

        foreach (array_unique(array_filter(array_column($choices, 'fingerprint'))) as $fingerprint) {
            $banners[$fingerprint] = ConsentSnapshot::find($fingerprint);
        }

        return [
            'consent_id' => strtolower(trim($id)),
            'exported_at' => now()->toIso8601String(),
            'choices' => $choices,
            'banners' => $banners,
        ];
    }

    /**
     * How many choices of each kind were made on each banner, newest banner first: what an audit asks for.
     *
     * @return list<array{site: string, policy_version: int, fingerprint: string|null, accept_all: int, reject_all: int, custom: int, first: string, last: string}>
     */
    public static function totals(): array
    {
        $rows = ConsentSnapshot::connection()
            ->table((string) config('core-web.consent.log.table', 'core_web_consents'))
            ->selectRaw('site, policy_version, fingerprint, action, count(*) as total, min(created_at) as first, max(created_at) as last')
            ->groupBy('site', 'policy_version', 'fingerprint', 'action')
            ->get();

        return $rows
            ->groupBy(fn (object $row): string => $row->site.'|'.$row->policy_version.'|'.$row->fingerprint)
            ->map(fn ($group): array => array_merge(
                ['site' => $group->first()->site, 'policy_version' => (int) $group->first()->policy_version, 'fingerprint' => $group->first()->fingerprint],
                array_fill_keys(Consent::ACTIONS, 0),
                $group->mapWithKeys(fn (object $row): array => [$row->action => (int) $row->total])->all(),
                ['first' => (string) $group->min('first'), 'last' => (string) $group->max('last')],
            ))
            ->sortByDesc('last')
            ->values()
            ->all();
    }
}
