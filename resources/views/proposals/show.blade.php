@extends('statamic::layout')
@section('title', $title)

@section('content')
    {{-- Statamic compiles this page as a Vue template, so source-written text sits in v-pre elements. --}}
    <ui-header title="{{ $title }}" icon="git-pull-request">
        @if ($proposal['status'] === 'open')
            <form method="POST" action="{{ cp_route('core-web.proposals.discard', $proposal['id']) }}" class="inline">
                @csrf
                <ui-button type="submit" text="{{ __('Discard') }}"></ui-button>
            </form>
            @unless ($proposal['stale'])
                <form method="POST" action="{{ cp_route('core-web.proposals.apply', $proposal['id']) }}" class="inline">
                    @csrf
                    <ui-button type="submit" variant="primary" text="{{ __('Apply') }}"></ui-button>
                </form>
            @endunless
        @elseif ($proposal['undoable'])
            <form method="POST" action="{{ cp_route('core-web.proposals.undo', $proposal['id']) }}" class="inline">
                @csrf
                <ui-button type="submit" text="{{ __('Undo') }}"></ui-button>
            </form>
        @endif
    </ui-header>

    <ui-card class="mb-6">
        <div class="space-y-1 text-sm">
            <div class="flex flex-wrap items-baseline gap-x-2">
                <span class="font-medium" v-pre>{{ $proposal['label'] }}</span>
                @if ($targetUrl)
                    <a href="{{ $targetUrl }}" class="text-blue-600 underline">{{ __('Open') }}</a>
                @endif
                @if ($proposal['status'] !== 'open')
                    <ui-badge size="sm" text="{{ __(ucfirst($proposal['status'])) }}"></ui-badge>
                @endif
            </div>
            <div class="text-gray-600 dark:text-gray-400"><span v-pre>{{ $proposal['by']['name'] ?? $proposal['by']['email'] ?? '' }}</span> · {{ $proposedAt }}</div>
            @if (filled($proposal['message']))
                <div class="text-gray-600 dark:text-gray-400" v-pre>{{ $proposal['message'] }}</div>
            @endif
            @if ($proposal['status'] === 'applied' && ! $proposal['undoable'])
                <ui-description>{{ __('This can no longer be undone here: it changed again after it was applied, or it was applied before undo existed.') }}</ui-description>
            @elseif ($proposal['status'] === 'open' && $proposal['stale'])
                <ui-description>{{ __('This changed after the proposal was made, so it can no longer be applied. Discard it and ask for a new one.') }}</ui-description>
            @endif
        </div>
    </ui-card>

    @php
        $summarize = fn (array $counts) => $before ? collect([
            __(':count added', ['count' => $counts['added']]) => $counts['added'],
            __(':count changed', ['count' => $counts['changed']]) => $counts['changed'],
            __(':count removed', ['count' => $counts['removed']]) => $counts['removed'],
            __(':count moved', ['count' => $counts['moved']]) => $counts['moved'],
        ])->filter()->keys()->implode(' · ') : '';
        $lines = fn (?string $text) => nl2br(e($text ?? ''));
    @endphp

    @if ($groups !== null)
        @php
            $values = array_values(array_filter($groups, fn (array $group) => $group['kind'] === 'value'));
            $grids = array_values(array_filter($groups, fn (array $group) => $group['kind'] === 'rows'));
        @endphp
        @if ($values !== [])
            <ui-card-panel heading="{{ __('Fields') }}" class="mb-6">
                <table class="data-table w-full table-fixed">
                    <thead>
                        <tr>
                            <th class="w-1/5 text-start">{{ __('Field') }}</th>
                            @if ($before)
                                <th class="text-start">{{ $proposal['status'] === 'open' ? __('Now') : __('Before') }}</th>
                            @endif
                            <th class="text-start">{{ __('Proposed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($values as $group)
                            <tr class="align-top">
                                <td class="text-sm font-medium" v-pre>{{ $group['label'] }}</td>
                                @foreach ($before ? ['from', 'to'] : ['to'] as $side)
                                    <td class="break-words text-sm" v-pre>
                                        <div @class(['text-red-700 dark:text-red-400' => $side === 'from', 'text-green-700 dark:text-green-400' => $side === 'to'])>
                                            @if ($group['raw'] && $group[$side] !== null)
                                                <pre class="whitespace-pre-wrap font-mono text-xs">{{ $group[$side] }}</pre>
                                            @elseif ($group[$side] === null || $group[$side] === '')
                                                <span class="text-gray-400">—</span>
                                            @else
                                                {!! $lines($group[$side]) !!}
                                            @endif
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </ui-card-panel>
        @endif

        @foreach ($grids as $group)
            <ui-card-panel heading="{{ e($group['label']) }}" subheading="{{ $summarize($group['counts']) }}" class="mb-6">
                @if ($group['rows'] !== [])
                    <table class="data-table w-full table-fixed">
                        <thead>
                            <tr>
                                @if ($before)
                                    <th class="w-28 text-start"></th>
                                @endif
                                @foreach ($group['columns'] as $label)
                                    <th class="text-start" v-pre>{{ $label }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group['rows'] as $row)
                                <tr class="align-top">
                                    @if ($before)
                                        <td>@include('core-web::proposals.badges', ['row' => $row])</td>
                                    @endif
                                    @foreach (array_keys($group['columns']) as $column)
                                        @php($cell = $row['cells'][$column] ?? ['from' => null, 'to' => null])
                                        <td class="break-words text-sm" v-pre>
                                            @if ($row['status'] === 'removed')
                                                <span class="text-gray-500 line-through">{!! $lines($cell['from']) !!}</span>
                                            @elseif ($row['status'] === 'changed' && $cell['from'] !== $cell['to'])
                                                <div class="text-red-700 line-through dark:text-red-400">{!! $lines($cell['from']) !!}</div>
                                                <div class="text-green-700 dark:text-green-400">{!! $lines($cell['to']) !!}</div>
                                            @else
                                                {!! $lines($cell['to']) !!}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </ui-card-panel>
        @endforeach

        @if ($groups === [])
            <ui-card><ui-description>{{ __('Nothing changes.') }}</ui-description></ui-card>
        @endif
    @elseif ($tree !== null)
        <ui-card-panel heading="{{ __('Proposed tree') }}" subheading="{{ $summarize($tree['counts']) }}">
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($tree['rows'] as $row)
                    <div class="flex items-baseline gap-2 py-1.5 text-sm" style="padding-inline-start: {{ $row['depth'] * 1.75 }}rem">
                        @if ($row['depth'] > 0)
                            <span class="text-gray-300 dark:text-gray-600">└</span>
                        @endif
                        <span @class([
                            'text-gray-500 line-through' => $row['status'] === 'removed',
                            'text-green-700 dark:text-green-400' => in_array($row['status'], ['added', 'changed'], true),
                            'text-gray-500 dark:text-gray-400' => $row['status'] === null && ! $row['moved'],
                        ]) v-pre>
                            @if ($row['was'] !== null)
                                <span class="me-1 text-red-700 line-through dark:text-red-400">{{ $row['was'] }}</span>
                            @endif
                            {{ $row['label'] }}
                        </span>
                        @if ($row['url'] !== null)
                            <span class="truncate text-xs text-gray-400" v-pre>{{ $row['url'] }}</span>
                        @endif
                        @if ($before)
                            @include('core-web::proposals.badges', ['row' => $row])
                        @endif
                    </div>
                @endforeach
            </div>
        </ui-card-panel>
    @else
        <ui-card-panel heading="{{ __('What changes') }}">
            <table class="data-table w-full table-fixed">
                <thead>
                    <tr>
                        <th class="w-1/4 text-start">{{ __('Where') }}</th>
                        @if ($before)
                            <th class="text-start">{{ $proposal['status'] === 'open' ? __('Now') : __('Before') }}</th>
                        @endif
                        <th class="text-start">{{ __('Proposed') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($changes as $where => $change)
                        <tr class="align-top">
                            <td class="text-sm">
                                <span class="break-words" v-pre>{{ $where }}</span>
                                @if ($before && $change['from'] === null)
                                    <ui-badge size="sm" color="green" text="{{ __('Added') }}"></ui-badge>
                                @elseif ($change['to'] === null)
                                    <ui-badge size="sm" color="red" text="{{ __('Removed') }}"></ui-badge>
                                @endif
                            </td>
                            @if ($before)
                                <td><div class="whitespace-pre-wrap break-words text-xs text-red-700 dark:text-red-400" v-pre>{{ $change['from'] ?? '' }}</div></td>
                            @endif
                            <td><div class="whitespace-pre-wrap break-words text-xs text-green-700 dark:text-green-400" v-pre>{{ $change['to'] ?? '' }}</div></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </ui-card-panel>
    @endif
@endsection
