{{-- Statamic compiles widget HTML as a Vue template, so content text sits in v-pre elements. --}}
<ui-widget title="{{ $title }}" icon="checkmark">
    <div class="px-4 py-3 space-y-4">
        @foreach ($rows as $row)
            <div>
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">{{ $row['description'] }}</span>
                    @if ($row['error'])
                        <ui-badge color="red" text="{{ __('Failed') }}"></ui-badge>
                    @elseif ($row['ran_at'] === null)
                        <ui-badge text="{{ __('Not run yet') }}"></ui-badge>
                    @elseif ($row['count'] === 0)
                        <ui-badge color="green" text="{{ __('All clear') }}"></ui-badge>
                    @else
                        <ui-badge color="amber" text="{{ $row['count'] }}"></ui-badge>
                    @endif
                </div>
                @foreach ($row['findings'] as $finding)
                    <div class="flex items-start justify-between gap-2 py-1 text-sm">
                        <div class="min-w-0">
                            <div class="line-clamp-1" v-pre>{{ $finding['label'] }}</div>
                            <div class="text-xs text-gray-600 dark:text-gray-400" v-pre>{{ $finding['message'] }}</div>
                        </div>
                        @if ($finding['cp_url'])
                            <ui-button size="xs" variant="ghost" href="{{ $finding['cp_url'] }}" text="{{ __('Open') }}"></ui-button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</ui-widget>
