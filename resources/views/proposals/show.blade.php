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

    @if ($groups !== null)
        @forelse ($groups as $group)
            @if ($group['kind'] === 'rows')
                @php
                    $summary = collect([
                        __(':count added', ['count' => $group['counts']['added']]) => $group['counts']['added'],
                        __(':count changed', ['count' => $group['counts']['changed']]) => $group['counts']['changed'],
                        __(':count removed', ['count' => $group['counts']['removed']]) => $group['counts']['removed'],
                    ])->filter()->keys();
                    if ($group['reordered']) {
                        $summary->push(__('order changed'));
                    }
                @endphp
                <ui-card-panel heading="{{ $group['label'] }}" subheading="{{ $summary->implode(' · ') }}" class="mb-6">
                    @if ($group['rows'] !== [])
                        <table class="data-table w-full table-fixed">
                            <thead>
                                <tr>
                                    <th class="w-24 text-start"></th>
                                    @foreach ($group['columns'] as $label)
                                        <th class="text-start" v-pre>{{ $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($group['rows'] as $row)
                                    <tr class="align-top">
                                        <td>
                                            @if ($row['status'] === 'added')
                                                <ui-badge size="sm" color="green" text="{{ __('Added') }}"></ui-badge>
                                            @elseif ($row['status'] === 'removed')
                                                <ui-badge size="sm" color="red" text="{{ __('Removed') }}"></ui-badge>
                                            @else
                                                <ui-badge size="sm" color="amber" text="{{ __('Changed') }}"></ui-badge>
                                            @endif
                                        </td>
                                        @foreach (array_keys($group['columns']) as $column)
                                            @php($cell = $row['cells'][$column] ?? ['from' => null, 'to' => null])
                                            <td class="break-words text-sm" v-pre>
                                                @if ($row['status'] === 'removed')
                                                    <span class="text-gray-500 line-through">{{ $cell['from'] }}</span>
                                                @elseif ($row['status'] === 'changed' && $cell['from'] !== $cell['to'])
                                                    <div class="text-red-700 line-through dark:text-red-400">{{ $cell['from'] }}</div>
                                                    <div class="text-green-700 dark:text-green-400">{{ $cell['to'] }}</div>
                                                @else
                                                    {{ $cell['to'] }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </ui-card-panel>
            @else
                <ui-card-panel heading="{{ $group['label'] }}" class="mb-6">
                    <div @class(['grid gap-4', 'md:grid-cols-2' => $before])>
                        @if ($before)
                            <div>
                                <div class="mb-1 text-xs text-gray-500">{{ $proposal['status'] === 'open' ? __('Now') : __('Before') }}</div>
                                <div @class(['whitespace-pre-wrap break-words text-sm text-red-700 dark:text-red-400', 'font-mono text-xs' => $group['raw']]) v-pre>{{ $group['from'] ?? '—' }}</div>
                            </div>
                        @endif
                        <div>
                            <div class="mb-1 text-xs text-gray-500">{{ __('Proposed') }}</div>
                            <div @class(['whitespace-pre-wrap break-words text-sm text-green-700 dark:text-green-400', 'font-mono text-xs' => $group['raw']]) v-pre>{{ $group['to'] ?? '—' }}</div>
                        </div>
                    </div>
                </ui-card-panel>
            @endif
        @empty
            <ui-card><ui-description>{{ __('Nothing changes.') }}</ui-description></ui-card>
        @endforelse
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
