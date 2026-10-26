@if ($rules !== '')
{!! $rules !!}

@endif
@foreach ($training as $agent)
User-agent: {{ $agent }}
Disallow: /

@endforeach
@unless ($aiSearch)
@foreach ($search as $agent)
User-agent: {{ $agent }}
Disallow: /

@endforeach
@endunless
User-agent: *
Allow: /
Content-Signal: ai-train=no, search=yes, ai-input={{ $aiSearch ? 'yes' : 'no' }}

@foreach ($sitemaps as $sitemap)
Sitemap: {!! $sitemap !!}
@endforeach
