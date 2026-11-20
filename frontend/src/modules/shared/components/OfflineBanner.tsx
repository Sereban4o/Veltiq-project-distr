import { useEffect, useState } from 'react'
import { Box, Typography } from '@mui/material'
import { CloudOff as CloudOffIcon } from '@mui/icons-material'

/**
 * Small banner shown while the browser reports no connectivity. Cached data
 * (service worker) keeps the last viewed pages readable offline.
 */
export default function OfflineBanner() {
  const [offline, setOffline] = useState(typeof navigator !== 'undefined' ? !navigator.onLine : false)

  useEffect(() => {
    const goOnline = () => setOffline(false)
    const goOffline = () => setOffline(true)

    window.addEventListener('online', goOnline)
    window.addEventListener('offline', goOffline)

    return () => {
      window.removeEventListener('online', goOnline)
      window.removeEventListener('offline', goOffline)
    }
  }, [])

  if (!offline) return null

  return (
    <Box
      sx={{
        position: 'fixed',
        bottom: 16,
        left: '50%',
        transform: 'translateX(-50%)',
        zIndex: 2100,
        bgcolor: 'warning.main',
        color: 'warning.contrastText',
        px: 2,
        py: 1,
        borderRadius: 2,
        display: 'flex',
        alignItems: 'center',
        gap: 1,
        boxShadow: 3,
      }}
    >
      <CloudOffIcon fontSize="small" />
      <Typography variant="body2">Нет сети — показаны сохранённые данные</Typography>
    </Box>
  )
}
