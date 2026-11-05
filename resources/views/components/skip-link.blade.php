@props(['target' => 'main-content', 'label' => null])
{{-- The first Tab stop (WCAG 2.4.1, Bypass Blocks): hidden until it is focused, it jumps past the header and navigation to the element with the id `target`, or to the page's main element when no element has that id. Styled by core-skip-link.css; a site's own classes merge in. --}}
<a href="#{{ $target }}" {{ $attributes->merge(['class' => 'core-skip-link']) }}>{{ $label ?? __('Skip to content') }}</a>
<script>
    (() => {
        const link = document.currentScript?.previousElementSibling;

        if (! link) {
            return;
        }

        const id = link.getAttribute('href').slice(1);

        link.addEventListener('click', (event) => {
            const main = document.getElementById(id) ? null : document.querySelector('main');

            if (! main) {
                return;
            }

            event.preventDefault();
            main.hasAttribute('tabindex') || main.setAttribute('tabindex', '-1');
            main.focus({ preventScroll: true });
            main.scrollIntoView();
        });
@if (app()->isLocal())

        const warn = () => {
            if (! document.getElementById(id)) {
                console.warn(`core-web skip link: no element has the id "${id}"; ` + (document.querySelector('main') ? 'it falls back to <main>.' : 'it goes nowhere. Give the main content that id, or pass target.'));
            }
        };

        document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', warn) : warn();
@endif
    })();
</script>
