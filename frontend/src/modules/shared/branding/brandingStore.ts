import { create } from 'zustand'
import apiClient from '@/modules/shared/api/apiClient'
import { useThemeStore } from '@/modules/shared/store/themeStore'
import type { Branding } from './types'

/** Applies branding side effects that live outside the MUI theme. */
function applySideEffects(branding: Branding): void {
  if (typeof document === 'undefined') return

  if (branding.i18n?.appTitle || branding.appName) {
    document.title = branding.i18n?.appTitle || branding.appName
  }

  if (branding.favicon) {
    let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]')
    if (!link) {
      link = document.createElement('link')
      link.rel = 'icon'
      document.head.appendChild(link)
    }
    link.href = branding.favicon
  }

  if (typeof branding.customCss === 'string') {
    let style = document.getElementById('brand-custom-css') as HTMLStyleElement | null
    if (!style) {
      style = document.createElement('style')
      style.id = 'brand-custom-css'
      document.head.appendChild(style)
    }
    style.textContent = branding.customCss
  }
}

let started = false

interface BrandingState {
  branding: Branding | null
  loaded: boolean
  load: () => Promise<void>
}

/**
 * Runtime branding fetched from `GET /api/v1/branding`. Failures are ignored —
 * the built-in theme stays in place, so the app keeps working offline.
 */
export const useBrandingStore = create<BrandingState>((set) => ({
  branding: null,
  loaded: false,
  load: async () => {
    if (started) return
    started = true

    try {
      const response = await apiClient.get('/v1/branding')
      const branding = response.data?.data as Branding | undefined

      if (branding?.theme?.palette) {
        applySideEffects(branding)

        // Adopt the brand's default colour mode unless the user already chose one.
        const preferred = branding.theme.palette.mode
        if (preferred === 'light' || preferred === 'dark') {
          const saved = window.localStorage.getItem('app-theme')
          if (saved !== 'light' && saved !== 'dark') {
            useThemeStore.getState().setMode(preferred)
          }
        }

        set({ branding, loaded: true })
        return
      }
    } catch {
      /* branding is optional */
    }

    set({ loaded: true })
  },
}))
