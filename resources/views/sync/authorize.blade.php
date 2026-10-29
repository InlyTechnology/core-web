@extends('statamic::layout')
@section('title', $title)

@section('content')
    {{-- Statamic compiles this page as a Vue template, so text from elsewhere sits in plain v-pre elements (a component given v-pre is never rendered). --}}
    <ui-header title="{{ $title }}" icon="key"></ui-header>

    @if ($machine !== null)
        <ui-card>
            <div class="space-y-6">
                <h2 class="text-lg font-medium" v-pre>{{ __('core-web::sync.allow', ['site' => $site]) }}</h2>

                <dl class="space-y-3 text-sm" v-pre>
                    <div class="gap-4 sm:flex">
                        <dt class="shrink-0 text-gray-500 sm:w-32 dark:text-gray-400">{{ __('core-web::sync.machine') }}</dt>
                        <dd class="min-w-0">
                            <span class="font-mono" style="overflow-wrap: anywhere">{{ $machine }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('core-web::sync.machine_hint') }}</span>
                        </dd>
                    </div>
                    <div class="gap-4 sm:flex">
                        <dt class="shrink-0 text-gray-500 sm:w-32 dark:text-gray-400">{{ __('core-web::sync.requested') }}</dt>
                        <dd>{{ __('core-web::sync.requested_value', ['ip' => $ip ?? '?', 'ago' => $started ?? '?']) }}</dd>
                    </div>
                    <div class="gap-4 sm:flex">
                        <dt class="shrink-0 text-gray-500 sm:w-32 dark:text-gray-400">{{ __('core-web::sync.code') }}</dt>
                        <dd>
                            <span class="font-mono font-semibold tracking-wider">{{ $code }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('core-web::sync.code_hint') }}</span>
                        </dd>
                    </div>
                </dl>

                <div class="text-sm" v-pre>
                    <p class="font-medium">{{ __('core-web::sync.grants') }}</p>
                    <ul class="mt-2 space-y-1 ps-5" style="list-style-type: disc">
                        <li>{{ __('core-web::sync.grant_pull') }}</li>
                        <li>{{ __('core-web::sync.grant_snapshots') }}</li>
                        @if ($push)
                            <li>{{ __('core-web::sync.grant_push') }}</li>
                        @endif
                    </ul>
                    <p class="mt-3 text-gray-500 dark:text-gray-400">{{ __('core-web::sync.revoke_hint') }}</p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <p class="text-sm font-medium">{{ __('core-web::sync.only_yours') }}</p>
                    <div class="flex gap-2">
                        <form method="POST" action="{{ cp_route('core-web.sync.deny') }}">
                            @csrf
                            <input type="hidden" name="code" value="{{ $code }}">
                            <ui-button type="submit" text="{{ __('core-web::sync.deny') }}"></ui-button>
                        </form>
                        <form method="POST" action="{{ cp_route('core-web.sync.approve') }}">
                            @csrf
                            <input type="hidden" name="code" value="{{ $code }}">
                            <ui-button type="submit" variant="primary" text="{{ __('core-web::sync.approve') }}"></ui-button>
                        </form>
                    </div>
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
