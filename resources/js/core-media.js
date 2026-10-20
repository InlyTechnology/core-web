/*
 | The blurred placeholders of <x-core-img> and <x-core-video>, and the video's
 | autoplay. A placeholder lifts once its element has loaded or failed
 | (data-loaded), so it never shows through a transparent or letterboxed
 | picture; one already complete when swept is marked `instant` and just shows.
 | A video lifts on its poster or its first frame, whichever comes first, since
 | the CDN takes a while to build a new video's versions. `load` does not
 | bubble, so one capturing listener sees every element, including those
 | Livewire and Alpine add later, and a Livewire morph sweeps the component it
 | kept. Under prefers-reduced-motion, videos the component started stay
 | paused: call it before Alpine.start(), which walks the DOM while playback
 | begins.
 */
const IMAGES = 'img[data-placeholder]'
const VIDEOS = 'video[data-placeholder]'

const lift = (element, how = '') => {
    if (!element.hasAttribute('data-loaded')) element.setAttribute('data-loaded', how)
}

const settle = (event) => {
    if (event.target instanceof Element && event.target.matches(`${IMAGES}, ${VIDEOS}`)) lift(event.target)
}

const watchPoster = (video) => {
    if (video.hasAttribute('data-loaded')) return
    if (video.readyState >= 2 || !video.poster) return lift(video, 'instant')

    const poster = new Image()
    poster.onload = poster.onerror = () => lift(video)
    poster.src = video.poster
    if (poster.complete) lift(video, 'instant')
}

const sweep = (root) => {
    for (const img of root.querySelectorAll(IMAGES)) {
        if (img.complete) lift(img, 'instant')
    }

    for (const video of root.querySelectorAll(VIDEOS)) watchPoster(video)
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
    document.addEventListener('loadeddata', settle, true)
    sweep(document)
    document.addEventListener('livewire:init', () => {
        window.Livewire.hook('morphed', ({ el }) => sweep(el))
    })
}
