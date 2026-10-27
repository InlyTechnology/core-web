@props(['href' => null, 'variant' => 'primary', 'inline' => null, 'iconLeft' => null, 'iconRight' => null, 'attrs' => []])
<a href="{{ $href }}" data-variant="{{ $variant }}" data-inline="{{ var_export($inline, true) }}" data-icon-right="{{ $iconRight }}" {{ $attributes->merge($attrs) }}>{{ $slot }}</a>
