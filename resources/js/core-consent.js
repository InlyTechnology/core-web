/*
 | The cookie banner of core-web's consent feature. <x-core-web::consent.head>
 | puts the settings on window.coreConsent and Consent Mode's defaults in the
 | head; this shows the banner until the visitor chooses, keeps the choice in a
 | first-party cookie, runs the scripts and embeds each category held back
 | (<script type="text/plain" data-consent="statistics">, <x-core-web::consent.gate>),
 | updates Consent Mode, and logs the choice as proof of consent. A category
 | taken back deletes its cookies and reloads the page, since a script that ran
 | cannot be stopped. Once chosen, a corner button reopens the settings.
 | window.CoreConsent opens the settings and answers has().
 */
const settings = () => window.coreConsent || {}

// A sandboxed frame (an embedded preview) refuses cookies: read none and drop writes.
const cookies = () => {
    try {
        return document.cookie
    } catch {
        return ''
    }
}

const setCookie = (value) => {
    try {
        document.cookie = value
    } catch {}
}

const readChoice = () => {
    const { cookie, version, renewAfterDays } = settings()
    const match = cookies().match(new RegExp(`(?:^|; )${cookie}=([^;]*)`))
    if (!match) return null

    try {
        const choice = JSON.parse(decodeURIComponent(match[1]))
        if (choice.v !== version || !Array.isArray(choice.c)) return null
        if (Date.now() - choice.t * 1000 > renewAfterDays * 864e5) return null

        return choice
    } catch {
        return null
    }
}

const uuid = () =>
    crypto.randomUUID
        ? crypto.randomUUID()
        : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
              (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16),
          )

const writeChoice = (choice) => {
    const { cookie, renewAfterDays } = settings()
    const secure = location.protocol === 'https:' ? '; Secure' : ''
    setCookie(`${cookie}=${encodeURIComponent(JSON.stringify(choice))}; Max-Age=${renewAfterDays * 86400}; Path=/; SameSite=Lax${secure}`)
}

const deleteCookies = (names) => {
    const existing = cookies().split('; ').map((pair) => pair.split('=')[0])
    const host = location.hostname
    const domains = ['', host, `.${host}`, `.${host.split('.').slice(-2).join('.')}`]

    for (const name of existing) {
        const matches = names.some((pattern) => (pattern.endsWith('*') ? name.startsWith(pattern.slice(0, -1)) : name === pattern))
        if (!matches) continue

        for (const domain of domains) {
            setCookie(`${name}=; Max-Age=0; Path=/${domain ? `; Domain=${domain}` : ''}`)
        }
    }
}

// data-consent-match="any" runs an element on any one of its categories (Tag Manager, which serves several).
const granted = (choice, categories, match = 'all') => {
    const list = categories.split(/\s+/).filter(Boolean)
    const has = (category) => choice?.c.includes(category)

    return match === 'any' ? list.some(has) : list.every(has)
}

const fresh = (held) => {
    const script = document.createElement('script')
    for (const { name, value } of held.attributes) {
        if (name === 'type' || name.startsWith('data-consent') || name === 'data-src') continue
        script.setAttribute(name, value)
    }
    if (held.dataset.consentType) script.type = held.dataset.consentType
    if (held.dataset.src) script.src = held.dataset.src
    else script.text = held.text

    return script
}

const runScript = (held) => held.replaceWith(fresh(held))

const release = (choice, root = document) => {
    for (const held of root.querySelectorAll('script[type="text/plain"][data-consent]')) {
        if (granted(choice, held.dataset.consent, held.dataset.consentMatch)) runScript(held)
    }

    for (const gate of root.querySelectorAll('[data-consent-gate]')) {
        if (!granted(choice, gate.dataset.consentGate)) continue

        const template = gate.querySelector(':scope > template')
        if (!template) continue
        const content = template.content.cloneNode(true)
        // Swapped before they reach the page, so each runs once and in order, as written.
        for (const script of content.querySelectorAll('script:not([type="text/plain"])')) script.replaceWith(fresh(script))
        gate.querySelector(':scope > [data-consent-placeholder]')?.remove()
        template.remove()
        gate.append(content)
        release(choice, gate)
    }
}

const updateConsentMode = (choice) => {
    if (!settings().consentMode || typeof window.gtag !== 'function') return

    const state = (category) => (choice.c.includes(category) ? 'granted' : 'denied')
    window.gtag('consent', 'update', {
        analytics_storage: state('statistics'),
        ad_storage: state('marketing'),
        ad_user_data: state('marketing'),
        ad_personalization: state('marketing'),
        functionality_storage: state('preferences'),
        personalization_storage: state('preferences'),
    })
}

const log = (choice, action) => {
    const url = settings().log
    if (!url) return

    fetch(url, {
        method: 'POST',
        keepalive: true,
        credentials: 'omit',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ id: choice.id, site: settings().site, action, categories: choice.c, version: choice.v }),
    }).catch(() => {})
}

const showStatus = (choice) => {
    for (const status of document.querySelectorAll('[data-consent-status]')) {
        const labels = JSON.parse(status.dataset.labels || '{}')
        status.textContent = choice ? ['necessary', ...choice.c].map((category) => labels[category] || category).join(', ') : status.dataset.none
    }

    for (const line of document.querySelectorAll('[data-consent-id-line]')) {
        line.hidden = !choice
        const id = line.querySelector('[data-consent-id]')
        if (id) id.textContent = choice?.id ?? ''
    }
}

let current = null
const listeners = []

const OPTIONAL = ['preferences', 'statistics', 'marketing']

const choose = (categories, action) => {
    const optional = OPTIONAL
    const next = {
        id: current?.id ?? uuid(),
        v: settings().version,
        c: optional.filter((category) => categories.includes(category)),
        t: Math.floor(Date.now() / 1000),
    }
    const withdrawn = (current?.c ?? []).filter((category) => !next.c.includes(category))

    writeChoice(next)
    current = next
    log(next, action)
    updateConsentMode(next)
    hideBanner()
    closeDialog()
    showStatus(next)
    window.dataLayer?.push({ event: 'consent_update', consent_categories: next.c })
    window.dispatchEvent(new CustomEvent('core-consent:change', { detail: { categories: next.c } }))
    for (const listener of listeners) listener(next.c)

    // Also after a re-ask, when an earlier choice's cookies may still be there.
    deleteCookies(OPTIONAL.filter((category) => !next.c.includes(category)).flatMap((category) => settings().cookies?.[category] ?? []))

    if (withdrawn.length > 0) {
        location.reload()

        return
    }

    release(next)
}

const banner = () => document.querySelector('[data-consent-banner]')
const dialog = () => document.querySelector('[data-consent-dialog]')
const fab = () => document.querySelector('[data-consent-fab]')
const showFab = () => fab() && (fab().hidden = false)
const hideBanner = () => {
    if (banner()) banner().hidden = true
    showFab()
}
const closeDialog = () => dialog()?.open && dialog().close()

const openDialog = () => {
    const element = dialog()
    if (!element) return

    for (const input of element.querySelectorAll('input[name="consent-category"]:not([disabled])')) {
        input.checked = current?.c.includes(input.value) ?? false
    }
    element.showModal()
}

const onClick = (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-consent-accept-all], [data-consent-reject-all], [data-consent-save], [data-consent-open], [data-consent-close], [data-consent-grant]') : null
    if (!target) return

    event.preventDefault()

    if (target.hasAttribute('data-consent-accept-all')) return choose(settings().categories || [], 'accept_all')
    if (target.hasAttribute('data-consent-reject-all')) return choose([], 'reject_all')
    if (target.hasAttribute('data-consent-open')) return openDialog()
    if (target.hasAttribute('data-consent-close')) return closeDialog()
    if (target.hasAttribute('data-consent-grant')) return choose([...(current?.c ?? []), target.dataset.consentGrant], 'custom')

    const checked = [...(dialog()?.querySelectorAll('input[name="consent-category"]:checked:not([disabled])') ?? [])].map((input) => input.value)
    choose(checked, 'custom')
}

export default function coreConsent() {
    current = readChoice()
    document.addEventListener('click', onClick)
    showStatus(current)

    if (current) {
        release(current)
        showFab()
    } else if (banner()) {
        banner().hidden = false
    }

    // Livewire swaps markup in after load; release what its morph added.
    const hookLivewire = () => window.Livewire.hook('morphed', ({ el }) => current && release(current, el))
    if (window.Livewire) hookLivewire()
    else document.addEventListener('livewire:init', hookLivewire)

    window.CoreConsent = {
        open: openDialog,
        has: (category) => category === 'necessary' || (current?.c.includes(category) ?? false),
        categories: () => ['necessary', ...(current?.c ?? [])],
        onChange: (listener) => listeners.push(listener),
    }
}
