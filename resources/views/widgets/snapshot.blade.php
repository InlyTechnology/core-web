{{-- Statamic compiles widget HTML as a Vue template, so Core's text sits in v-pre elements: a title with braces stays text. --}}
<ui-widget title="{{ $title }}" icon="{{ $icon }}">
    <template #actions>
        @if (! $loaded)
            <ui-badge text="{{ __('Loading') }}"></ui-badge>
        @elseif ($pinned)
            <ui-badge color="amber" text="{{ __('Local copy') }}"></ui-badge>
        @elseif ($stale)
            <ui-badge color="amber" text="{{ __('May be out of date') }}"></ui-badge>
        @endif
        @if ($loaded)
            <ui-badge text="{{ trans_choice(':count item|:count items', $total, ['count' => $total]) }}"></ui-badge>
        @endif
    </template>

    @if (! $loaded)
        <ui-description class="flex-1 flex items-center justify-center">{{ __('Loading. Reload the page in a moment.') }}</ui-description>
    @elseif ($total === 0)
        <ui-description class="flex-1 flex items-center justify-center">{{ __('No results') }}</ui-description>
    @else
        <div class="px-4 py-3 overflow-x-auto">
            <table class="w-full widget-table">
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td class="py-1 pe-2">
                                <span class="line-clamp-1" v-pre>{{ $row['title'] }}</span>
                            </td>
                            @if ($showPartition)
                                <td class="py-1 pe-2 text-xs text-gray-600 dark:text-gray-400 whitespace-nowrap" v-pre>{{ $row['partition'] }}</td>
                            @endif
                            <td class="py-1 text-end whitespace-nowrap">
                                @if ($row['core_url'])
                                    <ui-button size="xs" variant="ghost" icon-append="external-link" href="{{ $row['core_url'] }}" target="_blank" text="{{ __('View') }}"></ui-button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($pages > 1)
        <template #footer>
            <div class="flex items-center justify-between border-t border-gray-200 px-4 py-2 text-xs text-gray-600 dark:border-gray-700 dark:text-gray-400">
                <span>{{ __('Page :page of :pages', ['page' => $page, 'pages' => $pages]) }}</span>
                <div class="flex items-center gap-1">
                    <ui-button size="xs" variant="ghost" icon="chevron-left" icon-only preserve-scroll @if ($previousUrl) href="{{ $previousUrl }}" @else disabled @endif aria-label="{{ __('Previous page') }}"></ui-button>
                    <ui-button size="xs" variant="ghost" icon="chevron-right" icon-only preserve-scroll @if ($nextUrl) href="{{ $nextUrl }}" @else disabled @endif aria-label="{{ __('Next page') }}"></ui-button>
                </div>
            </div>
        </template>
    @endif
</ui-widget>
