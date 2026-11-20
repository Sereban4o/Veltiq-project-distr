import { useEffect, useRef, useState } from 'react'
import { Alert, Box, CircularProgress } from '@mui/material'
import apiClient from '@/modules/shared/api/apiClient'
import { previewUrl } from '../api/storageApi'
import type { PreviewStatus, StorageEditorMode } from '../types'
import ArchiveViewer from './ArchiveViewer'

interface Props {
  path: string
  name: string
  mode: StorageEditorMode
  mime?: string
  previewable?: boolean
  previewStatus?: PreviewStatus
}

async function fetchBlobUrl(path: string): Promise<string | null> {
  try {
    const res = await apiClient.get('/v1/storage/raw', { params: { path }, responseType: 'blob', timeout: 0 })
    return URL.createObjectURL(res.data as Blob)
  } catch {
    return null
  }
}

/**
 * Inline preview for images, PDFs, video and archives. Uses server-generated
 * thumbnails when available and falls back to native rendering or an icon.
 */
export default function FilePreview({ path, name, mode, previewable, previewStatus }: Props) {
  const [objectUrl, setObjectUrl] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const urlRef = useRef<string | null>(null)

  useEffect(() => {
    if (urlRef.current) {
      URL.revokeObjectURL(urlRef.current)
      urlRef.current = null
    }
    setObjectUrl(null)

    if (!['image', 'pdf', 'video'].includes(mode)) return

    let cancelled = false
    setLoading(true)
    void fetchBlobUrl(path).then((url) => {
      if (cancelled) {
        if (url) URL.revokeObjectURL(url)
        return
      }
      urlRef.current = url
      setObjectUrl(url)
      setLoading(false)
    })

    return () => {
      cancelled = true
    }
  }, [path, mode])

  useEffect(
    () => () => {
      if (urlRef.current) URL.revokeObjectURL(urlRef.current)
    },
    [],
  )

  if (mode === 'archive') {
    return <ArchiveViewer path={path} />
  }

  if (mode === 'video') {
    if (!objectUrl && loading) return <CircularProgress size={22} />
    if (!objectUrl) return <Alert severity="warning">Не удалось открыть видео</Alert>

    return (
      <Box
        component="video"
        controls
        src={objectUrl}
        sx={{ width: '100%', maxHeight: '70vh', bgcolor: '#000', borderRadius: 1 }}
      />
    )
  }

  if (mode === 'pdf') {
    if (!objectUrl) return <CircularProgress size={22} />
    return (
      <Box
        component="iframe"
        title={name}
        src={objectUrl}
        sx={{ width: '100%', height: '70vh', border: 'none', bgcolor: '#fff' }}
      />
    )
  }

  if (mode === 'image') {
    const showServerThumb = previewable && previewStatus !== 'failed' && previewStatus !== 'none'
    const src = showServerThumb ? previewUrl(path, 'medium') : objectUrl

    if (!src) return <CircularProgress size={22} />
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center' }}>
        <Box
          component="img"
          src={src}
          alt={name}
          onError={() => setObjectUrl(objectUrl)}
          sx={{ maxWidth: '100%', maxHeight: '70vh' }}
        />
      </Box>
    )
  }

  return <Alert severity="info">Предпросмотр для этого формата недоступен</Alert>
}
