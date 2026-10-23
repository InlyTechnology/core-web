@if (\Inly\CoreWeb\Consent\Consent::setting('branding.show_mark', true))
    <p class="core-consent__mark">
        <span class="core-consent__mark-logo" aria-hidden="true">{!! \Inly\CoreWeb\Consent\Consent::mark() !!}</span>
        {{ __('Consent by Inly Core') }}
    </p>
@endif
