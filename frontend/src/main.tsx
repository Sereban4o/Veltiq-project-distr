import React, { useMemo } from 'react'
import ReactDOM from 'react-dom/client'
// Self-hosted fonts (bundled by Vite, served from our server — no Google Fonts).
import '@fontsource/inter/400.css'
import '@fontsource/inter/500.css'
import '@fontsource/inter/600.css'
import '@fontsource/inter/700.css'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { DndProvider } from 'react-dnd'
import { HTML5Backend } from 'react-dnd-html5-backend'
import { ThemeProvider } from '@mui/material/styles'
import CssBaseline from '@mui/material/CssBaseline'
import { createAppTheme } from './theme'
import { useThemeStore } from './modules/shared/store/themeStore'
import { useBrandingStore } from './modules/shared/branding/brandingStore'
import App from './App'
import { registerServiceWorker } from './modules/shared/offline/pwa'
import { loadBundleId } from './modules/shared/api/bundleId'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      refetchOnWindowFocus: false,
      retry: 1,
      staleTime: 5 * 60 * 1000,
    },
  },
})

const rootElement = document.getElementById('root')

/** Rebuilds the MUI theme on light/dark switch or runtime branding update. */
function Root() {
  const mode = useThemeStore((state) => state.mode)
  const branding = useBrandingStore((state) => state.branding)

  const activeTheme = useMemo(
    () =>
      createAppTheme(mode, {
        primary: branding?.theme.palette.primary,
        secondary: branding?.theme.palette.secondary,
        borderRadius: branding?.theme.shape.borderRadius,
        fontFamily: branding?.theme.typography.fontFamily,
      }),
    [mode, branding],
  )

  return (
    <ThemeProvider theme={activeTheme}>
      <CssBaseline />
      <App />
    </ThemeProvider>
  )
}

if (rootElement) {
  ReactDOM.createRoot(rootElement).render(
    <React.StrictMode>
      <QueryClientProvider client={queryClient}>
        <DndProvider backend={HTML5Backend}>
          <Root />
        </DndProvider>
      </QueryClientProvider>
    </React.StrictMode>,
  )
}

// Offline support (production only; no-op during development).
registerServiceWorker()

// Runtime branding/theme (npm run build → /api/v1/branding); optional, never blocks.
void useBrandingStore.getState().load()

// Bundle identity for the server-side integrity check.
void loadBundleId()
