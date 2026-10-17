{{-- A picture from a Statamic Asset, a URL, or a Core file; see "Media" in the README. --}}
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
])
@php
    $source = \Inly\CoreWeb\Media\MediaSource::from($src);
    $image = $source ? \Inly\CoreWeb\Media\ImageRendition::for($source, [
        'width' => $width,
        'height' => $height,
        'quality' => $quality,
        'fit' => $fit,
        'format' => $format,
        'widths' => $widths,
        'sizes' => $sizes,
        'placeholder' => $placeholder,
        'trim' => $trim,
        'ratio' => $ratio,
        'styled' => $attributes->has('style'),
    ]) : null;
@endphp
@if ($image)
<img
    src="{{ $image->src }}"
    @if ($image->srcset) srcset="{{ $image->srcset }}" @endif
    @if ($image->sizes) sizes="{{ $image->sizes }}" @endif
    alt="{{ $alt ?? $source->alt }}"
    {{ $attributes->merge(['loading' => 'lazy', 'decoding' => 'async', ...$image->attributes]) }}
>
@endif
