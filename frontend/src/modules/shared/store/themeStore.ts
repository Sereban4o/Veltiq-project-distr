import { create } from 'zustand'
import type { ThemeMode } from '../../../theme'

const STORAGE_KEY = 'app-theme'

function apply(mode: ThemeMode): void {
  if (typeof document === 'undefined') return
  document.documentElement.setAttribute('data-theme', mode)
  document.documentElement.style.colorScheme = mode
}

function initialMode(): ThemeMode {
  if (typeof window === 'undefined') return 'light'
  const saved = window.localStorage.getItem(STORAGE_KEY)
  if (saved === 'light' || saved === 'dark') return saved
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

const start = initialMode()
apply(start)

interface ThemeState {
  mode: ThemeMode
  toggle: () => void
  setMode: (mode: ThemeMode) => void
}

/** UI colour mode, persisted in localStorage and defaulting to the OS setting. */
export const useThemeStore = create<ThemeState>((set) => ({
  mode: start,
  toggle: () =>
    set((state) => {
      const mode: ThemeMode = state.mode === 'dark' ? 'light' : 'dark'
      window.localStorage.setItem(STORAGE_KEY, mode)
      apply(mode)
      return { mode }
    }),
  setMode: (mode) => {
    window.localStorage.setItem(STORAGE_KEY, mode)
    apply(mode)
    set({ mode })
  },
}))
