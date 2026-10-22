@extends('statamic::layout')
@section('title', $title)

@section('content')
    {{-- Statamic compiles this page as a Vue template, so source-written text sits in v-pre elements. --}}
    <ui-header title="{{ $title }}" icon="git-pull-request"></ui-header>

    @if ($proposals === [])
        <ui-card>
            <ui-description>{{ __('No open proposals. Changes to navigations, page trees, globals, terms and asset details that agents and pushes suggest show up here for you to apply.') }}</ui-description>
        </ui-card>
    @else
        <ui-card-panel>
            <table class="data-table w-full">
                <thead>
                    <tr>
                        <th class="text-start">{{ __('Change to') }}</th>
                        <th class="text-start">{{ __('Proposed by') }}</th>
                        <th class="text-start">{{ __('Message') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($proposals as $proposal)
                        <tr>
                            <td>
                                <a href="{{ $proposal['cp_url'] }}" v-pre>{{ $proposal['label'] }}</a>
                                @if ($proposal['stale'])
                                    <ui-badge color="amber" text="{{ __('Changed since') }}"></ui-badge>
                                @endif
                            </td>
                            <td v-pre>{{ $proposal['by']['name'] ?? $proposal['by']['email'] ?? '' }}</td>
                            <td class="text-sm text-gray-600 dark:text-gray-400" v-pre>{{ $proposal['message'] }}</td>
                            <td class="text-end">
                                <ui-button size="sm" href="{{ $proposal['cp_url'] }}" text="{{ __('Review') }}"></ui-button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </ui-card-panel>
    @endif

    @if ($applied !== [])
        <ui-card-panel heading="{{ __('Recently applied') }}" class="mt-6">
            <ui-description class="mb-2">{{ __('Navigations, page trees, globals and terms keep no revisions, so each applied proposal can be undone here until the same thing changes again.') }}</ui-description>
            <table class="data-table w-full">
                <thead>
                    <tr>
                        <th class="text-start">{{ __('Change to') }}</th>
                        <th class="text-start">{{ __('Applied by') }}</th>
                        <th class="text-start">{{ __('Applied') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($applied as $proposal)
                        <tr>
                            <td><a href="{{ $proposal['cp_url'] }}" v-pre>{{ $proposal['label'] }}</a></td>
                            <td v-pre>{{ $proposal['decided_by']['name'] ?? $proposal['decided_by']['email'] ?? '' }}</td>
                            <td class="text-sm text-gray-600 dark:text-gray-400" v-pre>{{ $proposal['decided_at'] }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ cp_route('core-web.proposals.undo', $proposal['id']) }}" class="inline">
                                    @csrf
                                    <ui-button size="sm" type="submit" text="{{ __('Undo') }}"></ui-button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </ui-card-panel>
    @endif
@endsection
