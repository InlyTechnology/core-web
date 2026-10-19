@extends('statamic::layout')
@section('title', $title)

@section('content')
    {{-- Statamic compiles this page as a Vue template, so text from elsewhere sits in v-pre elements. --}}
    <ui-header title="{{ $title }}" icon="key"></ui-header>

    @if ($machine !== null)
        <ui-card>
            <div class="space-y-4">
                <p class="text-lg" v-pre>{{ __('core-web::sync.allow', ['machine' => $machine]) }}</p>
                <ui-description v-pre>{{ __('core-web::sync.requested', ['ip' => $ip ?? '?', 'ago' => $started ?? '?']) }}</ui-description>
                <ui-description v-pre>{{ __('core-web::sync.allow_detail', ['site' => $site, 'code' => $code]) }}</ui-description>
                <div class="flex gap-2">
                    <form method="POST" action="{{ cp_route('core-web.sync.deny') }}" class="inline">
                        @csrf
                        <input type="hidden" name="code" value="{{ $code }}">
                        <ui-button type="submit" text="{{ __('core-web::sync.deny') }}"></ui-button>
                    </form>
                    <form method="POST" action="{{ cp_route('core-web.sync.approve') }}" class="inline">
                        @csrf
                        <input type="hidden" name="code" value="{{ $code }}">
                        <ui-button type="submit" variant="primary" text="{{ __('core-web::sync.approve') }}"></ui-button>
                    </form>
                </div>
            </div>
        </ui-card>
    @else
        <ui-card>
            <form method="GET" action="{{ cp_route('core-web.sync.authorize') }}" class="space-y-4">
                @if ($unknown)
                    <ui-description>{{ __('core-web::sync.code_unknown') }}</ui-description>
                @endif
                <label for="core-web-sync-code" class="block font-medium">{{ __('core-web::sync.enter_code') }}</label>
                <input id="core-web-sync-code" name="code" autocomplete="off" placeholder="ABCD-EFGH" class="input-text font-mono uppercase" maxlength="9">
                <ui-button type="submit" variant="primary" text="{{ __('core-web::sync.continue') }}"></ui-button>
            </form>
        </ui-card>
    @endif
@endsection
