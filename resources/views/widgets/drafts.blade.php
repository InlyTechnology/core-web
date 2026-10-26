{{-- Statamic compiles widget HTML as a Vue template, so content text sits in v-pre elements. --}}
<ui-widget title="{{ $title }}" icon="pencil">
    <template #actions>
        <ui-badge @if ($total > 0) color="amber" @endif text="{{ $total }}"></ui-badge>
    </template>

    @if ($total === 0)
        <ui-description class="flex-1 flex items-center justify-center">{{ __('No drafts waiting') }}</ui-description>
    @else
        <div class="px-4 py-3">
            @foreach ($rows as $row)
                <div class="flex items-start justify-between gap-2 py-1 text-sm">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="line-clamp-1 font-medium" v-pre>{{ $row['title'] }}</span>
                            @if ($row['new'])
                                <ui-badge size="sm" text="{{ __('New page') }}"></ui-badge>
                            @endif
                        </div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 line-clamp-1" v-pre>{{ $row['source'] }}@if ($row['message']) · {{ $row['message'] }}@endif @if ($row['ago']) · {{ $row['ago'] }}@endif</div>
                    </div>
                    <ui-button size="xs" variant="ghost" href="{{ $row['edit_url'] }}" text="{{ __('Review') }}"></ui-button>
                </div>
            @endforeach
        </div>
    @endif
</ui-widget>
