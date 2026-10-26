<?php

namespace Inly\CoreWeb\Widgets;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Inly\CoreWeb\Content\CoreAiAuthor;
use Inly\CoreWeb\Drafts\OpenDrafts;
use Statamic\Facades\User;
use Statamic\Widgets\Widget;

/**
 * The `core_drafts` dashboard widget: the drafts Core AI or a content push
 * wrote that nobody has published yet (OpenDrafts), newest first, each to
 * open in the CP.
 *
 *     ['type' => 'core_drafts', 'via' => ['core-ai'], 'limit' => 5, 'width' => 50]
 */
class DraftsWidget extends Widget
{
    protected static $handle = 'core_drafts';

    public const MAX_LIMIT = 50;

    public function html(): View|string
    {
        $via = array_values(array_filter(array_map('strval', (array) $this->config('via', [])), fn (string $source) => $source !== ''));
        $limit = (int) $this->config('limit', 5);
        $limit = $limit < 1 ? 5 : min(self::MAX_LIMIT, $limit);
        $open = app(OpenDrafts::class)->for(User::current(), $via, $limit);

        return view('core-web::widgets.drafts', [
            'title' => __((string) $this->config('title', 'Drafts to review')),
            'total' => $open['total'],
            'rows' => array_map(fn (array $draft): array => [
                'title' => $draft['title'],
                'source' => self::source($draft['via']),
                'message' => $draft['message'],
                'ago' => $draft['at']?->diffForHumans(),
                'edit_url' => $draft['edit_url'],
                'new' => $draft['kind'] === 'new_entry',
            ], $open['drafts']),
        ]);
    }

    private static function source(string $via): string
    {
        return match ($via) {
            CoreAiAuthor::VIA => __('Core AI'),
            'content-push' => __('Content push'),
            default => Str::headline($via),
        };
    }
}
