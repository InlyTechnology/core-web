{{-- In the head. Each link renders only when its file is set; the PNG's sizes lets a browser that reads SVG prefer the SVG, and the light link comes last for a browser that ignores media and takes the last. --}}
@php($tab = \Inly\CoreWeb\Favicons\Favicons::png(\Inly\CoreWeb\Favicons\Favicons::TAB))
@php($tabDark = \Inly\CoreWeb\Favicons\Favicons::png(\Inly\CoreWeb\Favicons\Favicons::TAB, null, 'favicon_dark'))
@php($svg = \Inly\CoreWeb\Favicons\Favicons::svg())
@php($svgDark = \Inly\CoreWeb\Favicons\Favicons::svg('favicon_svg_dark'))
@if ($tab && $tabDark)
<link rel="icon" type="image/png" sizes="32x32" href="{{ $tabDark }}" media="(prefers-color-scheme: dark)">
<link rel="icon" type="image/png" sizes="32x32" href="{{ $tab }}" media="(prefers-color-scheme: light)">
@elseif ($tab)
<link rel="icon" type="image/png" sizes="32x32" href="{{ $tab }}">
@endif
@if ($svg && $svgDark)
<link rel="icon" type="image/svg+xml" href="{{ $svgDark }}" media="(prefers-color-scheme: dark)">
<link rel="icon" type="image/svg+xml" href="{{ $svg }}" media="(prefers-color-scheme: light)">
@elseif ($svg)
<link rel="icon" type="image/svg+xml" href="{{ $svg }}">
@endif
@if ($tab)
<link rel="apple-touch-icon" sizes="180x180" href="{{ \Inly\CoreWeb\Favicons\Favicons::png(\Inly\CoreWeb\Favicons\Favicons::APPLE) }}">
<link rel="manifest" href="{{ \Inly\CoreWeb\Favicons\Favicons::manifestUrl() }}">
@endif
@if ($color = \Inly\CoreWeb\Favicons\Favicons::themeColor())
<meta name="theme-color" content="{{ $color }}">
@endif
