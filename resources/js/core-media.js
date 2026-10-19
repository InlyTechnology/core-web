/*
 | <x-core-img>'s blurred placeholders and <x-core-video>'s autoplay. An image's
 | placeholder lifts once it has loaded or failed (data-loaded), so it never
 | shows through a transparent or letterboxed picture; one already complete
 | when swept is marked `instant` and just shows. `load` does not bubble, so one
 | capturing listener sees every image, including those Livewire and Alpine add
 | later, and a Livewire morph sweeps the component it kept. Under
 | prefers-reduced-motion, videos the component started stay paused: call it
 | before Alpine.start(), which walks the DOM while playback begins.
 */
const SELECTOR = 'img[data-placeholder]'

const settle = (event) => {
    if (event.target instanceof HTMLImageElement && event.target.matches(SELECTOR)) {
        event.target.setAttribute('data-loaded', '')
    }
}

const sweep = (root) => {
    for (const img of root.querySelectorAll(SELECTOR)) {
        if (img.complete) img.setAttribute('data-loaded', 'instant')
    }
}

export default function coreMedia() {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        for (const video of document.querySelectorAll('video[data-autoplay]')) {
            video.autoplay = false
            video.load()
        }
    }

    document.addEventListener('load', settle, true)
    document.addEventListener('error', settle, true)
    sweep(document)
    document.addEventListener('livewire:init', () => {
        window.Livewire.hook('morphed', ({ el }) => sweep(el))
    })
}
