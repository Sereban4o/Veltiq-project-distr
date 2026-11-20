/**
 * Bundle identity for integrity checks.
 *
 * The build writes `dist/asset-manifest.json` with a `bundleId` (hash of every
 * emitted file). We report it on API requests so the backend can flag a bundle
 * that was modified or is not the expected release. Purely informational — the
 * backend never blocks on a mismatch.
 */
let bundleId: string | null | undefined
let loading: Promise<string | null> | null = null

export async function loadBundleId(): Promise<string | null> {
  if (bundleId !== undefined) return bundleId
  if (loading) return loading

  loading = (async () => {
    bundleId = null
    try {
      const response = await fetch('/asset-manifest.json', { cache: 'no-store' })
      if (response.ok) {
        const data = (await response.json()) as { bundleId?: string }
        if (typeof data?.bundleId === 'string' && data.bundleId !== '') {
          bundleId = data.bundleId
        }
      }
    } catch {
      /* integrity info is optional */
    }

    return bundleId
  })()

  return loading
}

/** Bundle id if it has been resolved already, otherwise null. */
export function getBundleId(): string | null {
  return bundleId ?? null
}
