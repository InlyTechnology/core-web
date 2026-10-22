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
            <div><strong>{{ __('Change to') }}:</strong> <span v-pre>{{ $proposal['label'] }}</span>
                @if ($targetUrl)
                    <a href="{{ $targetUrl }}" class="text-blue-600 underline">{{ __('Open') }}</a>
                @endif
            </div>
            <div><strong>{{ __('Proposed by') }}:</strong> <span v-pre>{{ $proposal['by']['name'] ?? $proposal['by']['email'] ?? '' }}</span>, {{ $proposal['created_at'] }}</div>
            @if (filled($proposal['message']))
                <div v-pre>{{ $proposal['message'] }}</div>
            @endif
            @if ($proposal['status'] !== 'open')
                <ui-badge text="{{ __(ucfirst($proposal['status'])) }}"></ui-badge>
                @if ($proposal['status'] === 'applied' && ! $proposal['undoable'])
                    <ui-description>{{ __('This can no longer be undone here: it changed again after it was applied, or it was applied before undo existed.') }}</ui-description>
                @endif
            @elseif ($proposal['stale'])
                <ui-description>{{ __('This changed after the proposal was made, so it can no longer be applied. Discard it and ask for a new one.') }}</ui-description>
            @endif
        </div>
    </ui-card>

    <ui-card-panel heading="{{ __('What changes') }}">
        <table class="data-table w-full">
            <thead>
                <tr>
                    <th class="text-start">{{ __('Where') }}</th>
                    <th class="text-start">{{ $proposal['status'] === 'open' ? __('Now') : __('Before') }}</th>
                    <th class="text-start">{{ __('Proposed') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($changes as $where => $change)
                    <tr>
                        <td class="font-mono text-xs" v-pre>{{ $where }}</td>
                        <td><pre class="whitespace-pre-wrap text-xs text-red-700 dark:text-red-400" v-pre>{{ $change['from'] ?? '' }}</pre></td>
                        <td><pre class="whitespace-pre-wrap text-xs text-green-700 dark:text-green-400" v-pre>{{ $change['to'] ?? '' }}</pre></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </ui-card-panel>
@endsection
