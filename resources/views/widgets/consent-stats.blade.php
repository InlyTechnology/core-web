<ui-widget title="{{ $title }}" icon="charts-donut-graph">
    <template #actions>
        <ui-badge text="{{ $period }}"></ui-badge>
    </template>

    @if ($total === 0)
        <ui-description class="flex-1 flex items-center justify-center">{{ __('No choices logged in this period') }}</ui-description>
    @else
        <div class="px-4 py-3 space-y-4 text-sm">
            <div>
                <div class="text-2xl font-medium tabular-nums">{{ number_format($total, 0, ',', ' ') }}</div>
                <div class="text-xs text-gray-600 dark:text-gray-400">{{ $multisite ? __('Choices made, all sites') : __('Choices made') }}</div>
            </div>

            <div>
                <div class="flex h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                    @foreach ($actions as $action)
                        <div class="{{ $action['color'] }}" style="width: {{ $action['percent'] }}%"></div>
                    @endforeach
                </div>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 dark:text-gray-400">
                    @foreach ($actions as $action)
                        <span class="flex items-center gap-1"><span class="inline-block size-2 rounded-full {{ $action['color'] }}"></span>{{ $action['label'] }} <span class="tabular-nums">{{ $action['percent'] }}%</span></span>
                    @endforeach
                </div>
            </div>

            @if ($categories !== [])
                <div class="space-y-2">
                    <div class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ __('Allowed') }}</div>
                    @foreach ($categories as $category)
                        <div>
                            <div class="flex justify-between text-xs">
                                <span>{{ $category['label'] }}</span>
                                <span class="tabular-nums">{{ $category['percent'] }}%</span>
                            </div>
                            <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                <div class="h-full bg-blue-500" style="width: {{ $category['percent'] }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</ui-widget>
