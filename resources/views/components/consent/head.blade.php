{{-- In <head>, before any tag: Consent Mode's denied defaults, and a returning visitor's choice applied at once. --}}
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
    (function (settings) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + settings.cookie + '=([^;]*)'));
        if (!match) return;
        try {
            var choice = JSON.parse(decodeURIComponent(match[1]));
            if (choice.v !== settings.version || Date.now() - choice.t * 1000 > settings.renewAfterDays * 864e5) return;
            var has = function (category) { return choice.c.indexOf(category) !== -1 ? 'granted' : 'denied'; };
            gtag('consent', 'update', {
                analytics_storage: has('statistics'),
                ad_storage: has('marketing'),
                ad_user_data: has('marketing'),
                ad_personalization: has('marketing'),
                functionality_storage: has('preferences'),
                personalization_storage: has('preferences')
            });
        } catch (e) {}
    })(window.coreConsent);
@endif
</script>
