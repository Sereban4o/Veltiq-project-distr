/*
 * Offline support for the web app.
 *
 * Strategy:
 *  - navigations  -> network first, fall back to the cached SPA shell (index.html)
 *  - GET /api/... -> network first with a runtime cache (last data wins offline)
 *  - static files -> cache first (Vite hashes asset names, so they are immutable)
 *
 * Auth endpoints (`/api/v1/auth/*`) are never cached. The runtime cache is
 * cleared on logout via a `clear-cache` message from the app.
 */

const VERSION = 'v1'
const SHELL_CACHE = 'veltiq-shell-' + VERSION
const RUNTIME_CACHE = 'veltiq-runtime-' + VERSION

const SHELL_ASSETS = [
  '/',
  '/index.html',
  '/manifest.webmanifest',
  '/veltiq-icon-192.png',
  '/veltiq-icon-512.png',
]

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(SHELL_CACHE)
      .then((cache) => cache.addAll(SHELL_ASSETS))
      .catch(() => undefined),
  )
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((key) => key !== SHELL_CACHE && key !== RUNTIME_CACHE).map((key) => caches.delete(key)),
      ),
    ),
  )
  self.clients.claim()
})

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'clear-cache') {
    event.waitUntil(caches.delete(RUNTIME_CACHE))
  }
})

async function networkFirst(request, cacheName, fallbackUrl) {
  const cache = await caches.open(cacheName)
  try {
    const response = await fetch(request)
    if (response && response.ok) {
      cache.put(request, response.clone())
    }
    return response
  } catch (error) {
    const cached = await cache.match(request)
    if (cached) return cached
    if (fallbackUrl) {
      const shell = await caches.match(fallbackUrl)
      if (shell) return shell
    }
    throw error
  }
}

async function cacheFirst(request, cacheName) {
  const cache = await caches.open(cacheName)
  const cached = await cache.match(request)
  if (cached) return cached
  const response = await fetch(request)
  if (response && response.ok) {
    cache.put(request, response.clone())
  }
  return response
}

self.addEventListener('fetch', (event) => {
  const request = event.request
  if (request.method !== 'GET') return

  const url = new URL(request.url)
  if (url.origin !== self.location.origin) return

  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(request, SHELL_CACHE, '/index.html'))
    return
  }

  if (url.pathname.indexOf('/api/') === 0) {
    // Never cache auth (tokens + user data) or binary downloads.
    if (url.pathname.indexOf('/api/v1/auth/') === 0) return
    if (url.pathname.indexOf('/api/v1/storage/raw') === 0) return
    event.respondWith(networkFirst(request, RUNTIME_CACHE))
    return
  }

  event.respondWith(cacheFirst(request, SHELL_CACHE))
})
