{{-- Opens the cookie settings again, so a visitor can withdraw as easily as they gave consent; for the footer. --}}
<button type="button" data-consent-open {{ $attributes->merge(['class' => 'core-consent-settings-link']) }}>{{ $slot->isEmpty() ? __('Cookie settings') : $slot }}</button>
