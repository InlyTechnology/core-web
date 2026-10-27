{{-- In <head>, before any tag: every consent API's denied defaults, and a returning visitor's choice applied at once. --}}
@php($signals = \Inly\CoreWeb\Consent\ConsentVendors::signals())
<script>
    window.coreConsent = @js(\Inly\CoreWeb\Consent\Consent::script());
@if (\Inly\CoreWeb\Consent\Consent::setting('google.consent_mode', true))
    window.dataLayer = window.dataLayer || [];
    function gtag() { dataLayer.push(arguments); }
    gtag('consent', 'default', {
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        analytics_storage: 'denied',
        functionality_storage: 'denied',
        personalization_storage: 'denied',
        security_storage: 'granted',
        wait_for_update: 500
    });
@endif
    (function (settings) {
        var choice = null;
        var match = document.cookie.match(new RegExp('(?:^|; )' + settings.cookie + '=([^;]*)'));
        try {
            var read = match && JSON.parse(decodeURIComponent(match[1]));
            if (read && Array.isArray(read.c) && read.v === settings.version && (read.s === undefined || read.s === settings.scope) && Date.now() - read.t * 1000 <= settings.renewAfterDays * 864e5) choice = read;
        } catch (e) {}
        var has = function (category) { return choice && choice.c.indexOf(category) !== -1 ? 'granted' : 'denied'; };

@if (\Inly\CoreWeb\Consent\Consent::setting('google.consent_mode', true))
        if (choice) {
            gtag('consent', 'update', {
                analytics_storage: has('statistics'),
                ad_storage: has('marketing'),
                ad_user_data: has('marketing'),
                ad_personalization: has('marketing'),
                functionality_storage: has('preferences'),
                personalization_storage: has('preferences')
            });
        }
@endif
@if (in_array('uet', $signals, true))
        window.uetq = window.uetq || [];
        window.uetq.push('consent', 'default', { ad_storage: 'denied' });
        if (choice) window.uetq.push('consent', 'update', { ad_storage: has('marketing') });
@endif
@if (in_array('clarity', $signals, true))
        window.clarity = window.clarity || function () { (window.clarity.q = window.clarity.q || []).push(arguments); };
        window.clarity('consentv2', { ad_Storage: has('marketing'), analytics_Storage: has('statistics') });
@endif
    })(window.coreConsent);
</script>
