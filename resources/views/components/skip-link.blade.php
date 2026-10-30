@props(['target' => 'content', 'label' => null])
{{-- The first Tab stop (WCAG 2.4.1, Bypass Blocks): hidden until it is focused, it jumps past the header and navigation to the element with the id `target`. Styled by core-skip-link.css; a site's own classes merge in. --}}
<a href="#{{ $target }}" {{ $attributes->merge(['class' => 'core-skip-link']) }}>{{ $label ?? __('Skip to content') }}</a>
