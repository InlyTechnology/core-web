{{-- Statamic compiles a utility's HTML as a Vue template, so every recorded value sits in v-pre elements. --}}
<ui-header title="{{ __('Consent log') }}" icon="security-lock"></ui-header>

<ui-panel heading="{{ __('Find a visitor\'s consent') }}">
    <ui-card>
        <form method="GET" action="{{ $action }}" class="flex flex-wrap items-center gap-2">
            <input type="text" name="id" value="{{ $id }}" placeholder="{{ __('Consent id') }}" class="input-text flex-1 min-w-64 font-mono text-sm" v-pre>
            <ui-button type="submit" text="{{ __('Find') }}"></ui-button>
            @if ($evidence && $evidence['choices'] !== [])
                <ui-button href="{{ $export }}" variant="ghost" icon="download" text="{{ __('Download evidence') }}"></ui-button>
            @endif
        </form>
        <ui-description class="mt-2">{{ __('The visitor finds their consent id in the cookie settings, under "Show consent details".') }}</ui-description>
    </ui-card>
</ui-panel>

@if ($evidence)
    <ui-panel heading="{{ __('Recorded choices') }}">
        <ui-card>
            @if ($evidence['choices'] === [])
                <ui-description>{{ __('No consent is recorded under this id.') }}</ui-description>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left">
                            <th class="py-1 pr-3">{{ __('Chosen at') }}</th>
                            <th class="py-1 pr-3">{{ __('Site') }}</th>
                            <th class="py-1 pr-3">{{ __('Choice') }}</th>
                            <th class="py-1 pr-3">{{ __('Categories') }}</th>
                            <th class="py-1 pr-3">{{ __('Version') }}</th>
                            <th class="py-1">{{ __('Banner') }}</th>
                        </tr>
                    </thead>
                    <tbody v-pre>
                        @foreach ($evidence['choices'] as $choice)
                            <tr class="border-t border-gray-200 dark:border-gray-800">
                                <td class="py-1 pr-3">{{ $choice['chosen_at'] }}</td>
                                <td class="py-1 pr-3">{{ $choice['site'] }} {{ $choice['locale'] }}</td>
                                <td class="py-1 pr-3">{{ $actions[$choice['action']] ?? $choice['action'] }}</td>
                                <td class="py-1 pr-3">{{ implode(', ', ['necessary', ...$choice['categories']]) }}</td>
                                <td class="py-1 pr-3">{{ $choice['policy_version'] }}</td>
                                <td class="py-1 font-mono">{{ $choice['fingerprint'] ? substr($choice['fingerprint'], 0, 12) : __('not recorded') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </ui-card>
    </ui-panel>

    @foreach ($evidence['banners'] as $fingerprint => $banner)
        <ui-panel heading="{{ __('Banner') }} {{ substr($fingerprint, 0, 12) }}">
            <ui-card>
                @if ($banner === null)
                    <ui-description>{{ __('No snapshot is kept for this banner: nobody chose on it while it was current.') }}</ui-description>
                @else
                    <div class="space-y-2 text-sm" v-pre>
                        <p class="font-medium">{{ $banner['texts']['title'] }}</p>
                        <p>{{ $banner['texts']['text'] }}</p>
                        @if ($banner['texts']['policy_url'])
                            <p class="font-mono text-xs">{{ $banner['texts']['policy_url'] }}</p>
                        @endif
                        @foreach ($banner['categories'] as $category => $vendors)
                            <p><strong>{{ $banner['texts']['categories'][$category]['title'] ?? $category }}</strong>: {{ $banner['texts']['categories'][$category]['text'] ?? '' }}</p>
                            <ul class="list-disc pl-5">
                                @foreach ($vendors as $vendor)
                                    <li>{{ $vendor['name'] }}: {{ implode(', ', array_column($vendor['cookies'], 'name')) }}</li>
                                @endforeach
                            </ul>
                        @endforeach
                    </div>
                @endif
            </ui-card>
        </ui-panel>
    @endforeach
@endif

<ui-panel heading="{{ __('Choices per banner') }}">
    <ui-card>
        @if ($totals === [])
            <ui-description>{{ __('No choices are recorded yet.') }}</ui-description>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="py-1 pr-3">{{ __('Site') }}</th>
                        <th class="py-1 pr-3">{{ __('Version') }}</th>
                        <th class="py-1 pr-3">{{ __('Banner') }}</th>
                        <th class="py-1 pr-3">{{ __('Accepted all') }}</th>
                        <th class="py-1 pr-3">{{ __('Rejected all') }}</th>
                        <th class="py-1 pr-3">{{ __('Chose') }}</th>
                        <th class="py-1">{{ __('Last') }}</th>
                    </tr>
                </thead>
                <tbody v-pre>
                    @foreach ($totals as $row)
                        <tr class="border-t border-gray-200 dark:border-gray-800">
                            <td class="py-1 pr-3">{{ $row['site'] }}</td>
                            <td class="py-1 pr-3">{{ $row['policy_version'] }}</td>
                            <td class="py-1 pr-3 font-mono">{{ $row['fingerprint'] ? substr($row['fingerprint'], 0, 12) : __('not recorded') }}</td>
                            <td class="py-1 pr-3">{{ $row['accept_all'] }}</td>
                            <td class="py-1 pr-3">{{ $row['reject_all'] }}</td>
                            <td class="py-1 pr-3">{{ $row['custom'] }}</td>
                            <td class="py-1">{{ $row['last'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </ui-card>
</ui-panel>
