{{-- What Core draws over a page in its preview frame, in Core's colours rather than the site's, and the messages the frame sends Core. --}}
<style>
    .core-framed { --core-ring: oklch(0.55 0.2 262); --core-ring-soft: oklch(0.55 0.2 262 / 0.06); --core-quiet: oklch(0.55 0.02 262); }
    .core-framed [data-core-field], .core-framed [data-core-block] { position: relative; border-radius: 3px; outline: 1.5px solid transparent; outline-offset: 6px; transition: outline-color 120ms ease, background-color 120ms ease; cursor: pointer; }
    .core-framed [data-core-hint]:hover { outline-color: var(--core-quiet); }
    .core-framed [data-core-highlighted] { outline-color: var(--core-ring); background: var(--core-ring-soft); box-shadow: 0 0 0 6px var(--core-ring-soft); }
    .core-framed [data-core-typeable]:hover { outline-color: var(--core-ring); background: var(--core-ring-soft); box-shadow: 0 0 0 6px var(--core-ring-soft); cursor: text; }
    .core-framed [data-core-hint]:not([contenteditable]):hover::after { content: attr(data-core-hint); position: absolute; right: -7.5px; bottom: calc(100% + 7.5px); padding: 0.2rem 0.6rem; border-radius: 4px 4px 0 0; background: var(--core-quiet); color: white; font: 500 0.9rem/1.4 ui-sans-serif, system-ui, sans-serif; white-space: nowrap; pointer-events: none; z-index: 2147483647; }
    .core-framed [data-core-typeable]:not([contenteditable]):hover::after { background: var(--core-ring); }
    .core-framed [contenteditable] { outline: 2px solid var(--core-ring); outline-offset: 6px; background: var(--core-ring-soft); box-shadow: 0 0 0 6px var(--core-ring-soft); cursor: text; }
</style>
<script>
    (function (core, labels) {
        if (window.parent === window) {
            return;
        }

        var typeable = [];

        document.documentElement.classList.add('core-framed');
        window.addEventListener('message', function (event) {
            if (event.source !== window.parent || event.origin !== core || !event.data) {
                return;
            }
            if (event.data.type === 'core-preview:highlight') {
                document.querySelectorAll('[data-core-highlighted]').forEach(function (element) {
                    element.removeAttribute('data-core-highlighted');
                });
                var block = typeof event.data.block === 'number' ? document.querySelector('[data-core-block="' + event.data.block + '"]') : null;
                if (block) {
                    block.setAttribute('data-core-highlighted', '');
                    // Scrolls this page only: scrollIntoView would also scroll the screen that frames it.
                    var box = block.getBoundingClientRect();
                    if (box.top < 0 || box.bottom > window.innerHeight) {
                        window.scrollTo({
                            top: window.scrollY + box.top - Math.max(16, (window.innerHeight - box.height) / 2),
                            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                        });
                    }
                }
                return;
            }
            if (event.data.type === 'core-preview:ready' && Array.isArray(event.data.editable)) {
                typeable = event.data.editable;
                // A hint only for what the screen can act on: a typeable field, a field its form has, a block it lists.
                document.querySelectorAll('[data-core-field], [data-core-block]').forEach(function (element) {
                    var field = element.dataset.coreField;
                    var canType = field !== undefined && element.hasAttribute('data-core-editable') && typeable.indexOf(field) !== -1;
                    var hint = canType ? labels.edit
                        : field !== undefined && event.data.form ? labels.form
                        : field === undefined && event.data.blocks ? labels.block
                        : null;

                    element.toggleAttribute('data-core-typeable', canType);
                    if (hint) {
                        element.setAttribute('data-core-hint', hint);
                    } else {
                        element.removeAttribute('data-core-hint');
                    }
                });
            }
        });

        var send = function (element, done) {
            window.parent.postMessage(done
                ? { type: 'core-preview:input', field: element.dataset.coreField, done: true }
                : { type: 'core-preview:input', field: element.dataset.coreField, value: element.textContent.replace(/\s*\n\s*/g, ' ') }, core);
        };

        var edit = function (element) {
            var input = function () { send(element, false); };
            var key = function (event) {
                if (event.key === 'Enter' || event.key === 'Escape') {
                    event.preventDefault();
                    element.blur();
                }
            };

            element.setAttribute('contenteditable', 'plaintext-only');
            element.focus();
            element.addEventListener('input', input);
            element.addEventListener('keydown', key);
            element.addEventListener('blur', function () {
                element.removeEventListener('input', input);
                element.removeEventListener('keydown', key);
                element.removeAttribute('contenteditable');
                send(element, true);
            }, { once: true });
        };

        document.addEventListener('click', function (event) {
            var marked = event.target.closest('[data-core-field], [data-core-block]');
            if (!marked) {
                return;
            }
            event.preventDefault();
            if (marked.isContentEditable) {
                return;
            }
            if (marked.hasAttribute('data-core-editable') && typeable.indexOf(marked.dataset.coreField) !== -1) {
                edit(marked);
                return;
            }
            window.parent.postMessage({
                type: 'core-preview:focus',
                field: marked.dataset.coreField || null,
                block: marked.dataset.coreBlock !== undefined ? Number(marked.dataset.coreBlock) : null,
            }, core);
        });
    })(@json($core), @json(['edit' => __('Edit inline'), 'form' => __('Edit'), 'block' => __('Show block')]));
</script>
