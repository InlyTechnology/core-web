@props(['page' => null, 'title' => null, 'description' => null, 'image' => null, 'url' => null, 'type' => null, 'noindex' => null])
{{-- In the head, after the consent head component when consent is on, so the Google tags read its defaults. --}}
@php($seo = \Inly\CoreWeb\Seo\SeoHead::for($page, array_filter(compact('title', 'description', 'image', 'url', 'type', 'noindex'), fn ($value) => $value !== null)))
<title>{{ $seo['title'] }}</title>
@if ($seo['description'])
<meta name="description" content="{{ $seo['description'] }}">
@endif
<meta name="robots" content="{{ $seo['robots'] }}">
@if ($seo['canonical'])
<link rel="canonical" href="{{ $seo['canonical'] }}">
@endif
@foreach ($seo['alternates'] as $alternate)
<link rel="alternate" hreflang="{{ $alternate['hreflang'] }}" href="{{ $alternate['href'] }}">
@endforeach
@stack('core-web.seo')
@foreach ($seo['og'] as $property => $content)
<meta property="{{ $property }}" content="{{ $content }}">
@endforeach
@foreach ($seo['twitter'] as $name => $content)
<meta name="{{ $name }}" content="{{ $content }}">
@endforeach
@foreach ($seo['json_ld'] as $json)
<script type="application/ld+json">{!! str_ireplace('</script', '<\/script', $json) !!}</script>
@endforeach
@if ($seo['verification'])
<meta name="google-site-verification" content="{{ $seo['verification'] }}">
@endif
@if ($tracker = $seo['trackers'])
@if ($tracker['type'] === 'gtm' && \Inly\CoreWeb\CoreWebServiceProvider::enabled('consent'))
<x-core-web::consent.google-tag-manager :id="$tracker['id']" />
@elseif ($tracker['type'] === 'gtm')
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!="dataLayer"?"&l="+l:"";j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i+dl;f.parentNode.insertBefore(j,f);})(window,document,"script","dataLayer",@js($tracker['id']));</script>
@else
@php($held = \Inly\CoreWeb\CoreWebServiceProvider::enabled('consent') && \Inly\CoreWeb\Consent\Consent::holdsGoogleTags())
<script>window.dataLayer = window.dataLayer || []; function gtag() { dataLayer.push(arguments); }</script>
@if ($held)
<script type="text/plain" data-consent="statistics" data-src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($tracker['id']) }}" async></script>
@else
<script src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($tracker['id']) }}" async></script>
@endif
<script{!! $held ? ' type="text/plain" data-consent="statistics"' : '' !!}>gtag('js', new Date()); gtag('config', @js($tracker['id']){!! $tracker['anonymize_ip'] ? ', { anonymize_ip: true }' : '' !!});</script>
@endif
@endif
