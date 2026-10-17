<?php

namespace Inly\CoreWeb\Widgets;

use Illuminate\Contracts\View\View;
use Inly\CoreWeb\Checks\ContentChecks;
use Statamic\Widgets\Widget;

/**
 * The `content_checks` dashboard widget: each check's latest findings from
 * its last run (`content:check`, nightly), never a run of its own, with the
 * first few to open in the CP.
 *
 *     ['type' => 'content_checks', 'limit' => 5, 'width' => 50]
 */
class ContentChecksWidget extends Widget
{
    protected static $handle = 'content_checks';

    public function html(): View|string
    {
        $checks = app(ContentChecks::class);
        $limit = max(1, (int) $this->config('limit', 5));
        $rows = [];

        foreach ($checks->all() as $key => $check) {
            $latest = $checks->latest($key);
            $rows[] = [
                'key' => $key,
                'description' => $check->description(),
                'ran_at' => $latest['ran_at'] ?? null,
                'error' => $latest['error'] ?? null,
                'count' => count($latest['findings'] ?? []),
                'findings' => array_slice($latest['findings'] ?? [], 0, $limit),
            ];
        }

        return view('core-web::widgets.content-checks', [
            'title' => $this->config('title', __('Content checks')),
            'rows' => $rows,
        ]);
    }
}
