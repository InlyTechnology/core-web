{{--
    A `buttons` field's rows, each resolved by Inly\CoreWeb\Buttons\Buttons and drawn
    by the site's own button component (core-web.buttons.component). A row
    whose link resolves to nothing is left out. With no `class` the row lays
    out with a plain flex style, so it needs no classes from this package.
--}}
@props(['buttons' => [], 'align' => 'start'])

@php
    $rows = collect(\Inly\CoreWeb\Support\Values::collect($buttons))
        ->map(fn ($button) => \Inly\CoreWeb\Buttons\Buttons::resolve($button))
        ->filter(fn (array $button) => $button['href'] !== '' && $button['label'] !== null);
    $component = (string) config('core-web.buttons.component', 'ui.button');
    $inline = (bool) config('core-web.buttons.inline');
    $justify = ['center' => 'center', 'end' => 'flex-end'][$align] ?? 'flex-start';
@endphp

@if ($rows->isNotEmpty())
    <div {{ $attributes->has('class') ? $attributes : $attributes->merge(['style' => "display:flex;flex-wrap:wrap;gap:1rem;justify-content:{$justify}"]) }}>
        @foreach ($rows as $button)
            @if ($inline)
                <x-dynamic-component :component="$component" :href="$button['href']" :variant="$button['style']" :inline="$button['inline']" :icon-left="$button['icon_left']" :icon-right="$button['icon_right']" :attrs="$button['attrs']">{{ $button['label'] }}</x-dynamic-component>
            @else
                <x-dynamic-component :component="$component" :href="$button['href']" :variant="$button['style']" :icon-left="$button['icon_left']" :icon-right="$button['icon_right']" :attrs="$button['attrs']">{{ $button['label'] }}</x-dynamic-component>
            @endif
        @endforeach
    </div>
@endif
