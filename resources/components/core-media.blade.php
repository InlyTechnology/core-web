{{-- A picture or a video, whichever the source is: one field, the editor drops in either. Each gets only its own options. --}}
@props([
    'src' => null,
    'alt' => null,
    'width' => null,
    'height' => null,
    'quality' => null,
    'fit' => null,
    'format' => config('core-web.media.images.format', 'auto'),
    'widths' => null,
    'sizes' => null,
    'placeholder' => config('core-web.media.images.placeholder', 'blur'),
    'trim' => false,
    'ratio' => null,
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
@endphp
@if ($source?->isVideo())
<x-core-video :src="$source" :alt="$alt" :width="$width" :small-width="$smallWidth" :poster="$poster" :poster-time="$posterTime" :placeholder="$placeholder" :controls="$controls" :autoplay="$autoplay" :muted="$muted" :loop="$loop" :playsinline="$playsinline" :preload="$preload" {{ $attributes }} />
@elseif ($source)
<x-core-img :src="$source" :alt="$alt" :width="$width" :height="$height" :quality="$quality" :fit="$fit" :format="$format" :widths="$widths" :sizes="$sizes" :placeholder="$placeholder" :trim="$trim" :ratio="$ratio" {{ $attributes }} />
@endif
