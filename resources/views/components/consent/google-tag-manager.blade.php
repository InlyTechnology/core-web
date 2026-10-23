@props(['id'])
{{-- Basic mode holds the container back until statistics or marketing is granted, whose tags read Consent Mode; advanced loads it at once. --}}
@php($gtm = '(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);})(window,document,"script","dataLayer",'.\Illuminate\Support\Js::from((string) $id).');')
@if (\Inly\CoreWeb\Consent\Consent::holdsGoogleTags())
<script type="text/plain" data-consent="statistics marketing" data-consent-match="any">{!! $gtm !!}</script>
@else
<script>{!! $gtm !!}</script>
@endif
