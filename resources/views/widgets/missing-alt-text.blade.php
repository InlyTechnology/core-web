{{-- Statamic compiles widget HTML as a Vue template, so file names sit in v-pre elements. --}}
<ui-widget title="{{ $title }}" icon="image">
    <template #actions>
        @if (! $scanned)
            <ui-badge text="{{ __('Counting') }}"></ui-badge>
        @elseif ($count === 0)
            <ui-badge color="green" text="{{ __('All clear') }}"></ui-badge>
        @else
            <ui-badge color="amber" text="{{ $count }}"></ui-badge>
        @endif
    </template>

    @if (! $scanned)
        <ui-description class="flex-1 flex items-center justify-center">{{ __('Counting the images. Reload the page in a moment.') }}</ui-description>
    @elseif ($count === 0)
        <ui-description class="flex-1 flex items-center justify-center"><span v-pre>{{ __('Every image in :container has alt text.', ['container' => $container]) }}</span></ui-description>
    @else
        <div class="px-4 py-3">
            @foreach ($assets as $asset)
                <div class="flex items-center gap-3 py-1">
                    @if ($asset['thumbnail'])
                        <img src="{{ $asset['thumbnail'] }}" alt="" style="width:2rem;height:2rem;flex-shrink:0;object-fit:cover;border-radius:2px" loading="lazy">
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="line-clamp-1 text-sm" v-pre>{{ $asset['name'] }}</div>
                        @if ($asset['folder'])
                            <div class="line-clamp-1 text-xs text-gray-600 dark:text-gray-400" v-pre>{{ $asset['folder'] }}</div>
                        @endif
                    </div>
                    <ui-button size="xs" variant="ghost" href="{{ $asset['edit_url'] }}" text="{{ __('Add alt text') }}"></ui-button>
                </div>
            @endforeach
        </div>
    @endif
</ui-widget>
