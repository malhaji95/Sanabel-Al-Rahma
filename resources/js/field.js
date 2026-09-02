import Alpine from 'alpinejs'

/*
 | T-14 — the delegate field app.
 |
 | A visit is written to IndexedDB the moment it is saved, whether or not there
 | is a network. The queue is drained whenever the browser comes back online.
 | Every queued visit carries a client_uuid generated here; the unique index on
 | the server is what makes a repeated push idempotent.
 */

const DB_NAME = 'sanabel-field'
const DB_VERSION = 1
const STORE = 'visits'

function openDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION)

        request.onupgradeneeded = () => {
            const db = request.result

            if (!db.objectStoreNames.contains(STORE)) {
                const store = db.createObjectStore(STORE, { keyPath: 'client_uuid' })
                store.createIndex('synced', 'synced', { unique: false })
            }
        }

        request.onsuccess = () => resolve(request.result)
        request.onerror = () => reject(request.error)
    })
}

function tx(db, mode) {
    return db.transaction(STORE, mode).objectStore(STORE)
}

function promisify(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result)
        request.onerror = () => reject(request.error)
    })
}

export async function queueVisit(visit) {
    const db = await openDb()

    const record = {
        ...visit,
        client_uuid: visit.client_uuid || crypto.randomUUID(),
        synced: 0,
        queued_at: new Date().toISOString(),
    }

    await promisify(tx(db, 'readwrite').put(record))

    return record
}

export async function pendingVisits() {
    const db = await openDb()
    const all = await promisify(tx(db, 'readonly').getAll())

    return all.filter((visit) => visit.synced === 0)
}

async function markSynced(clientUuids) {
    const db = await openDb()
    const store = tx(db, 'readwrite')

    for (const uuid of clientUuids) {
        const record = await promisify(store.get(uuid))

        if (record) {
            record.synced = 1
            record.synced_at = new Date().toISOString()
            store.put(record)
        }
    }
}

/**
 * Pushes the whole queue in one request. A visit stays in IndexedDB until the
 * server confirms it, so a failed or partial sync simply retries next time.
 */
export async function sync() {
    const queue = await pendingVisits()

    if (queue.length === 0) {
        return { synced: 0, conflicts: 0 }
    }

    const response = await fetch('/api/visits/sync', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            visits: queue.map(({ synced, queued_at, ...visit }) => visit),
        }),
    })

    if (!response.ok) {
        throw new Error(`sync failed: ${response.status}`)
    }

    const result = await response.json()
    await markSynced(Object.keys(result.visit_ids ?? {}))

    return result
}

export function watchConnection(onChange) {
    const report = () => onChange(navigator.onLine)

    window.addEventListener('online', async () => {
        report()

        try {
            await sync()
        } catch {
            // Still offline in practice — the queue waits for the next attempt.
        }

        window.dispatchEvent(new CustomEvent('sanabel:queue-changed'))
    })

    window.addEventListener('offline', report)
    report()
}

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/field-sw.js').catch(() => {
            // Without a service worker the form still works; only the shell is not cached.
        })
    })
}

window.SanabelField = { queueVisit, pendingVisits, sync, watchConnection }

window.Alpine = Alpine
Alpine.start()
