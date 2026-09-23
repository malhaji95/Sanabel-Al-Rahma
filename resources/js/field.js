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
const DB_VERSION = 2
const STORE = 'visits'
const KEYS = 'keys'
const KEY_ID = 'queue'

function openDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION)

        request.onupgradeneeded = () => {
            const db = request.result

            if (!db.objectStoreNames.contains(STORE)) {
                const store = db.createObjectStore(STORE, { keyPath: 'client_uuid' })
                store.createIndex('synced', 'synced', { unique: false })
            }

            if (!db.objectStoreNames.contains(KEYS)) {
                db.createObjectStore(KEYS, { keyPath: 'id' })
            }
        }

        request.onsuccess = () => resolve(request.result)
        request.onerror = () => reject(request.error)
    })
}

function tx(db, mode, store = STORE) {
    return db.transaction(store, mode).objectStore(store)
}

function promisify(request) {
    return new Promise((resolve, reject) => {
        request.onsuccess = () => resolve(request.result)
        request.onerror = () => reject(request.error)
    })
}

/*
 | A phone carried into the field is lost or taken far more easily than a server
 | is breached, so a queued visit is encrypted before it is written.
 |
 | The key is generated in the browser and kept non-extractable: it is stored as
 | a CryptoKey, so no script on this origin can read its bytes back out. That is
 | not protection against someone holding the unlocked device, who can call our
 | own decrypt path; it removes the plaintext at rest, which is what a browser
 | can honestly offer without a passphrase the delegate would have to re-enter
 | with no network to check it against.
 */
async function queueKey(db) {
    const existing = await promisify(tx(db, 'readonly', KEYS).get(KEY_ID))

    if (existing?.key) {
        return existing.key
    }

    const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'])
    await promisify(tx(db, 'readwrite', KEYS).put({ id: KEY_ID, key }))

    return key
}

async function seal(db, visit) {
    const iv = crypto.getRandomValues(new Uint8Array(12))
    const body = new TextEncoder().encode(JSON.stringify(visit))

    return {
        iv,
        sealed: new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, await queueKey(db), body)),
    }
}

async function unseal(db, record) {
    const body = await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: record.iv },
        await queueKey(db),
        record.sealed,
    )

    return JSON.parse(new TextDecoder().decode(body))
}

export async function queueVisit(visit) {
    const db = await openDb()

    const body = { ...visit, client_uuid: visit.client_uuid || crypto.randomUUID() }
    const { iv, sealed } = await seal(db, body)

    // client_uuid and synced stay in the clear: one is the key, the other the
    // index the queue is read by. Neither says anything about a family.
    await promisify(tx(db, 'readwrite').put({
        client_uuid: body.client_uuid,
        synced: 0,
        queued_at: new Date().toISOString(),
        iv,
        sealed,
    }))

    return body
}

export async function pendingVisits() {
    const db = await openDb()
    const all = await promisify(tx(db, 'readonly').getAll())

    return Promise.all(all.filter((record) => record.synced === 0).map((record) => unseal(db, record)))
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
        body: JSON.stringify({ visits: queue }),
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
