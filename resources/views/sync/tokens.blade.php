@extends('statamic::layout')
@section('title', $title)

@section('content')
    {{-- Statamic compiles this page as a Vue template, so text from elsewhere sits in v-pre elements. --}}
    <ui-header title="{{ $title }}" icon="key"></ui-header>

    @if ($error !== null)
        <ui-card>
            <ui-description><span v-pre>{{ __('core-web::sync.store_unavailable') }} {{ $error }}</span></ui-description>
        </ui-card>
    @elseif ($tokens === [])
        <ui-card>
            <ui-description>{{ __('core-web::sync.no_tokens') }}</ui-description>
        </ui-card>
    @else
        <ui-card-panel>
            <table class="data-table w-full">
                <thead>
                    <tr>
                        <th class="text-start">{{ __('core-web::sync.machine') }}</th>
                        @if ($all)
                            <th class="text-start">{{ __('core-web::sync.user') }}</th>
                        @endif
                        <th class="text-start">{{ __('core-web::sync.last_used') }}</th>
                        <th class="text-start">{{ __('core-web::sync.expires') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tokens as $token)
                        <tr>
                            <td v-pre>{{ $token['machine'] }}</td>
                            @if ($all)
                                <td v-pre>{{ $token['user'] }}</td>
                            @endif
                            <td>{{ $token['last_used'] }}</td>
                            <td>{{ $token['expires'] }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ cp_route('core-web.sync.revoke', $token['id']) }}" class="inline">
                                    @csrf
                                    <ui-button type="submit" size="sm" variant="danger" text="{{ __('core-web::sync.revoke') }}"></ui-button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </ui-card-panel>
    @endif
@endsection
