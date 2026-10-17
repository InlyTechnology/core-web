{{-- A video from a Statamic Asset, a URL, or a Core file; see "Media" in the README. --}}
@props([
    'src' => null,
    'alt' => null,
    'width' => null,
    'smallWidth' => null,
    'poster' => null,
    'posterTime' => config('core-web.media.videos.poster_time', 0),
    'controls' => false,
    'autoplay' => true,
    'muted' => true,
    'loop' => true,
    'playsinline' => true,
    'preload' => 'metadata',
])
@php
    $source = \Inly\CoreWeb\Media\MediaSource::from($src);
    $video = $source?->isVideo() ? \Inly\CoreWeb\Media\VideoRendition::for($source, [
        'width' => $width,
        'small_width' => $smallWidth,
        'muted' => (bool) $muted,
        'poster' => $poster,
        'poster_time' => $posterTime,
    ]) : null;
    $label = $alt ?? $source?->alt;
@endphp
@if ($video)
{{-- data-autoplay is core-media.js's hook for pausing under prefers-reduced-motion. --}}
<video {{ $attributes->merge(array_filter([
    'autoplay' => (bool) $autoplay,
    'data-autoplay' => (bool) $autoplay,
    'muted' => (bool) $muted,
    'loop' => (bool) $loop,
    'playsinline' => (bool) $playsinline,
    'controls' => (bool) $controls,
    'preload' => $preload,
    'poster' => $video->poster,
    'aria-label' => $label ?: null,
])) }}>
@foreach ($video->sources as $file)
    <source {{ (new \Illuminate\View\ComponentAttributeBag)->merge(array_filter($file)) }}>
@endforeach
</video>
@endif
