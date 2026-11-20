/**
 * PWA / offline helpers.
 *
 * The service worker is only registered in a production build so the dev
 * server (HMR) is never shadowed by a stale cache.
 */
export function registerServiceWorker(): void {
  if (typeof window === 'undefined' || !('serviceWorker' in navigator)) return
  if (!import.meta.env.PROD) return

  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      /* offline support is optional */
    })
  })
}

/** Drop the offline runtime cache (called on logout so no data leaks). */
export function clearOfflineCaches(): void {
  if (typeof window === 'undefined' || !('serviceWorker' in navigator)) return
  navigator.serviceWorker.controller?.postMessage({ type: 'clear-cache' })
}
