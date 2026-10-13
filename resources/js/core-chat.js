/**
 * Alpine data for a chat with one of Core's agents, through the site's relay
 * (<x-core-web::chat>). Headless: the site's markup reads the state and calls
 * the methods. Register it once: Alpine.data('coreChat', coreChat).
 *
 * A message's `content` is the raw text, for x-text; its `html` was escaped
 * and rendered server side, so it is safe for x-html.
 */
export default function coreChat({ endpoint, subject = null, cookie = null } = {}) {
    return {
        messages: [],
        state: null,
        pending: false,
        error: null,
        handoff: null,

        init() {
            // An idle page makes no request: the transcript is fetched only while the session holds a conversation.
            if (cookie && hasCookie(cookie)) {
                this.load()
            }
        },

        async load() {
            const query = subject === null ? '' : `?subject=${encodeURIComponent(subject)}`

            return this.call('GET', '', null, query)
        },

        async send(text) {
            const message = String(text ?? '').trim()

            if (message === '' || this.pending) {
                return false
            }

            const optimistic = { id: `pending-${Date.now()}`, role: 'user', content: message, html: null, results: [], pending: true }
            this.messages = [...this.messages, optimistic]

            const ok = await this.call('POST', '/messages', { message, subject })

            if (!ok) {
                this.messages = this.messages.filter((m) => m !== optimistic)
            }

            return ok
        },

        async reset() {
            this.handoff = null

            return this.call('DELETE', '', { subject })
        },

        async requestHandoff() {
            if (this.pending) {
                return null
            }

            this.pending = true
            this.error = null

            try {
                const { ok, data } = await request('POST', `${endpoint}/handoff`, { subject })

                if (!ok) {
                    this.error = data.error ?? 'unavailable'

                    return null
                }

                this.handoff = data

                return data
            } catch {
                this.error = 'unavailable'

                return null
            } finally {
                this.pending = false
            }
        },

        async call(method, path, body, query = '') {
            this.pending = true
            this.error = null

            try {
                const { ok, data } = await request(method, `${endpoint}${path}${query}`, body)

                if (!ok) {
                    this.error = data.error ?? 'unavailable'

                    return false
                }

                this.state = data.state ?? null
                this.messages = Array.isArray(data.messages) ? data.messages : []

                return true
            } catch {
                this.error = 'unavailable'

                return false
            } finally {
                this.pending = false
            }
        },
    }
}

async function request(method, url, body) {
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    const token = csrfToken()

    if (token.meta) {
        headers['X-CSRF-TOKEN'] = token.meta
    } else if (token.cookie) {
        headers['X-XSRF-TOKEN'] = token.cookie
    }

    if (body !== null && body !== undefined) {
        headers['Content-Type'] = 'application/json'
    }

    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: body === null || body === undefined || method === 'GET' ? undefined : JSON.stringify(body),
    })

    let data = {}

    try {
        data = await response.json()
    } catch {
        data = {}
    }

    if (!response.ok && !data.error) {
        // 419: the session (and its CSRF token) expired; anything else without our error shape is the relay failing.
        data = { error: response.status === 419 ? 'expired' : 'unavailable' }
    }

    return { ok: response.ok, data }
}

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
    const cookie = readCookie('XSRF-TOKEN')

    return { meta, cookie: cookie === null ? '' : decodeURIComponent(cookie) }
}

function hasCookie(name) {
    return readCookie(name) !== null
}

function readCookie(name) {
    const prefix = `${name}=`
    const found = document.cookie.split('; ').find((part) => part.startsWith(prefix))

    return found === undefined ? null : found.slice(prefix.length)
}
