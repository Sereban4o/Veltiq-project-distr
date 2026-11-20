import { useEffect, useRef, useState } from 'react'
import { Alert, Box, CircularProgress } from '@mui/material'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapData } from '@/modules/shared/utils/apiResponse'

declare global {
  interface Window {
    DocsAPI?: any
  }
}

interface OfficeConfig {
  enabled: boolean
  documentServerUrl: string
  apiScript: string
  config: Record<string, unknown>
}

let scriptPromise: Promise<void> | null = null

/** Loads the ONLYOFFICE api.js once and resolves when DocsAPI is ready. */
function loadScript(src: string): Promise<void> {
  if (window.DocsAPI) return Promise.resolve()
  if (scriptPromise) return scriptPromise

  scriptPromise = new Promise<void>((resolve, reject) => {
    const script = document.createElement('script')
    script.src = src
    script.async = true
    script.onload = () => resolve()
    script.onerror = () => {
      scriptPromise = null
      reject(new Error('Не удалось загрузить редактор ONLYOFFICE'))
    }
    document.head.appendChild(script)
  })

  return scriptPromise
}

export default function OnlyOfficeEditor({
  path,
  configUrl,
  params,
  onSaved,
}: {
  path?: string
  configUrl?: string
  params?: Record<string, unknown>
  onSaved?: () => void
}) {
  const containerId = useRef(`onlyoffice-${Math.random().toString(36).slice(2)}`)
  const editorRef = useRef<any>(null)
  // Keep the latest params out of the effect deps: the parent may pass a fresh
  // object each render, which would otherwise restart the editor endlessly.
  const paramsRef = useRef(params)
  paramsRef.current = params
  // Same for the callback: a new function identity must not rebuild the editor
  // (that reloads the document and makes the UI flicker).
  const onSavedRef = useRef(onSaved)
  onSavedRef.current = onSaved
  const notifiedRef = useRef(false)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)

  const paramsKey = JSON.stringify(params ?? {})

  useEffect(() => {
    let cancelled = false

    const boot = async () => {
      try {
        const data = unwrapData<OfficeConfig>(
          await apiClient.get(configUrl ?? '/v1/storage/office/config', {
            params: configUrl ? paramsRef.current : { path },
          }),
        )

        if (!data || !data.enabled) {
          setError('Редактор ONLYOFFICE не настроен на сервере')
          return
        }

        await loadScript(data.apiScript)
        if (cancelled) return

        editorRef.current = new window.DocsAPI.DocEditor(containerId.current, {
          ...data.config,
          events: {
            // OnlyOffice autosaves continuously; notify the parent once per
            // session instead of on every edit to avoid toast/re-render spam.
            onDocumentStateChange: (event: any) => {
              if (event?.data === false && !notifiedRef.current) {
                notifiedRef.current = true
                onSavedRef.current?.()
              }
            },
          },
        })
      } catch (e: any) {
        setError(e?.response?.data?.message || e?.message || 'Не удалось открыть редактор')
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    boot()

    return () => {
      cancelled = true
      try {
        editorRef.current?.destroyEditor?.()
      } catch {
        /* ignore */
      }
      editorRef.current = null
      notifiedRef.current = false
    }
  }, [path, configUrl, paramsKey])

  if (error) {
    return <Alert severity="error">{error}</Alert>
  }

  return (
    <Box sx={{ position: 'relative', width: '100%', height: '100%', minHeight: 0, bgcolor: '#fff' }}>
      {loading && <CircularProgress sx={{ position: 'absolute', top: '50%', left: '50%', mt: -2, ml: -2 }} />}
      <Box id={containerId.current} sx={{ width: '100%', height: '100%' }} />
    </Box>
  )
}
