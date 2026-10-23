@props(['category' => 'marketing'])
@php($title = ['preferences' => __('preferences'), 'statistics' => __('statistics'), 'marketing' => __('marketing')][$category] ?? $category)
{{-- An embed (a HubSpot form, a booking frame, a video) rendered only once its category is granted. --}}
<div data-consent-gate="{{ $category }}" {{ $attributes }}>
    <template>{{ $slot }}</template>
    <div class="core-consent-gate" data-consent-placeholder @if ($style = \Inly\CoreWeb\Consent\Consent::brandingStyle()) style="{{ $style }}" @endif>
        <p>{{ __('This content uses :category cookies. Accept them to see it here.', ['category' => $title]) }}</p>
        <button type="button" class="core-consent__button" data-consent-grant="{{ $category }}">{{ __('Accept :category cookies', ['category' => $title]) }}</button>
        <button type="button" class="core-consent__button core-consent__button--outline" data-consent-open>{{ __('Cookie settings') }}</button>
    </div>
</div>
