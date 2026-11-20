import { useState } from 'react'
import { Alert, Box, Button, CircularProgress, Paper, TextField, Typography } from '@mui/material'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { publicShareApi } from '../api/storageApi'
import ArchiveViewer from '../components/ArchiveViewer'
import type { PublicShareInfo } from '../types'

function formatSize(size: number): string {
  if (size < 1024) return `${size} Б`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} КБ`
  return `${(size / (1024 * 1024)).toFixed(1)} МБ`
}

function kindOf(info: PublicShareInfo): 'image' | 'pdf' | 'video' | 'archive' | 'other' {
  const ext = info.name.split('.').pop()?.toLowerCase() ?? ''
  const mime = info.mime ?? ''

  if (mime.startsWith('image/') || ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'].includes(ext)) return 'image'
  if (ext === 'pdf') return 'pdf'
  if (['mp4', 'mov', 'mkv', 'webm', 'avi'].includes(ext)) return 'video'
  if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return 'archive'
  return 'other'
}

export default function PublicSharePage() {
  const { token = '' } = useParams()
  const [password, setPassword] = useState('')
  const [access, setAccess] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [unlocking, setUnlocking] = useState(false)

  const {
    data,
    isLoading,
    isError,
    error: queryError,
  } = useQuery({
    queryKey: ['public-share', token],
    queryFn: () => publicShareApi.info(token),
    retry: false,
  })

  const unlock = async () => {
    setUnlocking(true)
    setError(null)
    try {
      const accessToken = await publicShareApi.unlock(token, password)
      setAccess(accessToken)
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Не удалось открыть ссылку')
    } finally {
      setUnlocking(false)
    }
  }

  const download = () => {
    window.location.href = publicShareApi.downloadUrl(token, access)
  }

  if (isLoading) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100vh' }}>
        <CircularProgress />
      </Box>
    )
  }

  if (isError) {
    return (
      <Box sx={{ p: 4 }}>
        <Alert severity="error">{(queryError as any)?.response?.data?.message || 'Ссылка недоступна'}</Alert>
      </Box>
    )
  }

  const info = data as PublicShareInfo
  const kind = kindOf(info)
  const locked = info.requires_password && !access

  return (
    <Box
      sx={{
        display: 'flex',
        justifyContent: 'center',
        p: { xs: 2, md: 6 },
        minHeight: '100vh',
        bgcolor: 'background.default',
      }}
    >
      <Paper sx={{ p: 3, width: '100%', maxWidth: 900 }}>
        <Typography sx={{ fontSize: '1.1rem', fontWeight: 700, mb: 0.5 }}>{info.name}</Typography>
        <Typography sx={{ fontSize: '0.8rem', color: 'text.secondary', mb: 2 }}>
          {formatSize(info.size)}
          {info.expires_at ? ` · доступно до ${new Date(info.expires_at).toLocaleString('ru-RU')}` : ''}
          {info.downloads_left !== null ? ` · осталось скачиваний: ${info.downloads_left}` : ''}
        </Typography>

        {locked && (
          <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', mb: 2, flexWrap: 'wrap' }}>
            <TextField
              size="small"
              type="password"
              label="Пароль"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') void unlock()
              }}
            />
            <Button variant="contained" disabled={unlocking} onClick={unlock} sx={{ textTransform: 'none' }}>
              Открыть
            </Button>
            {error && <Alert severity="error">{error}</Alert>}
          </Box>
        )}

        {!locked && (
          <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            {kind === 'image' && (
              <Box
                component="img"
                src={publicShareApi.previewUrl(token, access)}
                alt={info.name}
                sx={{ maxWidth: '100%', maxHeight: '70vh', alignSelf: 'center' }}
              />
            )}
            {kind === 'pdf' && (
              <Box
                component="iframe"
                title={info.name}
                src={publicShareApi.rawUrl(token, access)}
                sx={{ width: '100%', height: '70vh', border: 'none', bgcolor: '#fff' }}
              />
            )}
            {kind === 'video' && (
              <Box
                component="video"
                controls
                src={publicShareApi.rawUrl(token, access)}
                sx={{ width: '100%', maxHeight: '70vh', bgcolor: '#000' }}
              />
            )}
            {kind === 'archive' && <ArchiveViewer path={token} />}

            <Box sx={{ display: 'flex', justifyContent: 'flex-end' }}>
              {info.can_download && (
                <Button
                  variant="contained"
                  disabled={info.downloads_left === 0}
                  onClick={download}
                  sx={{ textTransform: 'none' }}
                >
                  Скачать
                </Button>
              )}
            </Box>
          </Box>
        )}
      </Paper>
    </Box>
  )
}
