/*
 | Caches the field app shell so a delegate can open the form with no network.
 | Visit data itself lives in IndexedDB, not here.
 */
const CACHE = 'sanabel-field-v1'
const SHELL = ['/field', '/field/manifest.webmanifest']

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    )
})

self.addEventListener('fetch', (event) => {
    const { request } = event

    // Sync posts must never be served from cache.
    if (request.method !== 'GET') {
        return
    }

    event.respondWith(
        fetch(request)
            .then((response) => {
                const copy = response.clone()
                caches.open(CACHE).then((cache) => cache.put(request, copy))

                return response
            })
            .catch(() => caches.match(request).then((hit) => hit || caches.match('/field')))
    )
})
