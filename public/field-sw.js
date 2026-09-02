/*
 | Caches the field app shell so a delegate can open the form with no network.
 | Visit data itself lives in IndexedDB, not here.
 */
const CACHE = 'sanabel-field-v2'
const SHELL = [
    '/field',
    '/field/manifest.webmanifest',
    // The identity assets the shell needs with no network.
    '/brand/logo-symbol.png',
    '/brand/icon-192.png',
    '/fonts/ibm-plex-sans-arabic-arabic-400.woff2',
    '/fonts/ibm-plex-sans-arabic-arabic-600.woff2',
    '/fonts/ibm-plex-sans-arabic-arabic-700.woff2',
]

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
