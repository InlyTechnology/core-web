@props(['policyUrl' => null])
@if (\Inly\CoreWeb\Consent\Consent::asks())
@php
    $editorial = \Inly\CoreWeb\Consent\Consent::editorial();
    $policyUrl ??= $editorial['policy_url'] ?? \Inly\CoreWeb\Consent\Consent::setting('policy_url');
    $categories = \Inly\CoreWeb\Consent\ConsentVendors::byCategory();
    $button = \Inly\CoreWeb\Consent\Consent::setting('button', 'bottom-left');
    $branding = (array) \Inly\CoreWeb\Consent\Consent::setting('branding', []);
    $descriptions = [
        'necessary' => __('Needed for the site to work, such as remembering this choice. Always on.'),
        'preferences' => __('Remember your settings, such as language.'),
        'statistics' => __('Help us understand how the site is used, so we can improve it.'),
        'marketing' => __('Used to show relevant ads and measure our campaigns, here and on other sites.'),
    ];
    $titles = [
        'necessary' => __('Necessary'),
        'preferences' => __('Preferences'),
        'statistics' => __('Statistics'),
        'marketing' => __('Marketing'),
    ];
@endphp
{{-- The same markup for every visitor, so it is safe in a cached page; core-consent.js shows it. --}}
<div class="core-consent" data-core-consent @if ($style = \Inly\CoreWeb\Consent\Consent::brandingStyle()) style="{{ $style }}" @endif>
    <section class="core-consent__banner" data-consent-banner role="region" aria-labelledby="core-consent-title" hidden>
        <div class="core-consent__panel">
        <div class="core-consent__head">
            @if (! empty($branding['logo']))
                <img class="core-consent__logo" src="{{ $branding['logo'] }}" alt="">
            @endif
            <h2 class="core-consent__title" id="core-consent-title">{{ $editorial['title'] ?? __('We use cookies') }}</h2>
        </div>
        <p class="core-consent__text">
            {{ $editorial['text'] ?? __('We use cookies to make the site work, and with your consent to understand how it is used and to show relevant marketing. You choose which, and can change your mind at any time.') }}
            @if ($policyUrl)
                <a class="core-consent__link" href="{{ $policyUrl }}">{{ __('Read more about cookies') }}</a>
            @endif
        </p>
        <div class="core-consent__actions">
            <button type="button" class="core-consent__button core-consent__button--outline" data-consent-open>{{ __('Settings') }}</button>
            <button type="button" class="core-consent__button" data-consent-reject-all>{{ __('Reject all') }}</button>
            <button type="button" class="core-consent__button" data-consent-accept-all>{{ __('Accept all') }}</button>
        </div>
        @include('core-web::components.consent.mark')
        </div>
    </section>

    @if (in_array($button, ['bottom-left', 'bottom-right'], true))
        <button type="button" class="core-consent__fab core-consent__fab--{{ $button }}" data-consent-open data-consent-fab aria-label="{{ __('Cookie settings') }}" title="{{ __('Cookie settings') }}" hidden>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/><path d="M8.5 8.5v.01"/><path d="M16 15.5v.01"/><path d="M12 12v.01"/><path d="M11 17v.01"/><path d="M7 14v.01"/></svg>
        </button>
    @endif

    <dialog class="core-consent__dialog" data-consent-dialog aria-labelledby="core-consent-settings-title">
        <form method="dialog" class="core-consent__form core-consent__panel">
            <div class="core-consent__header">
                <h2 class="core-consent__title" id="core-consent-settings-title">{{ __('Cookie settings') }}</h2>
                <p class="core-consent__text">{{ __('Choose which cookies we may use. Necessary cookies are always on.') }}</p>
                <button type="button" class="core-consent__close" data-consent-close aria-label="{{ __('Close') }}">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>

            @foreach ($categories as $category => $vendors)
                <div class="core-consent__category">
                    <label class="core-consent__toggle">
                        <input type="checkbox" role="switch" name="consent-category" value="{{ $category }}" @if ($category === 'necessary') checked disabled @endif>
                        <span class="core-consent__category-title">{{ $titles[$category] }}</span>
                    </label>
                    <p class="core-consent__category-text">{{ $descriptions[$category] }}</p>
                    <details class="core-consent__details">
                        <summary>{{ __('Show cookies') }}</summary>
                        <ul class="core-consent__vendors">
                            @foreach ($vendors as $vendor)
                                <li>
                                    <strong>{{ $vendor['name'] }}</strong>
                                    @if (! empty($vendor['policy_url']))
                                        <a class="core-consent__link" href="{{ $vendor['policy_url'] }}" target="_blank" rel="noopener">{{ __('Privacy policy') }}</a>
                                    @endif
                                    <span class="core-consent__cookies">{{ collect($vendor['cookies'])->pluck('name')->implode(', ') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                </div>
            @endforeach

            <details class="core-consent__details core-consent__about" data-consent-id-line hidden>
                <summary>{{ __('Show consent details') }}</summary>
                <p>{{ __('Consent date:') }} <span data-consent-date></span></p>
                <p>{{ __('Your consent id:') }} <code data-consent-id></code></p>
            </details>

            <div class="core-consent__actions">
                <button type="button" class="core-consent__button core-consent__button--outline" data-consent-save>{{ __('Save choices') }}</button>
                <button type="button" class="core-consent__button" data-consent-reject-all>{{ __('Reject all') }}</button>
                <button type="button" class="core-consent__button" data-consent-accept-all>{{ __('Accept all') }}</button>
            </div>
            @include('core-web::components.consent.mark')
        </form>
    </dialog>
</div>
@endif
