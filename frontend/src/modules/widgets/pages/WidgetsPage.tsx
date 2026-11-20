import { useEffect } from 'react'
import { Alert, Box, Paper, Typography } from '@mui/material'
import { Extension as WidgetsIcon } from '@mui/icons-material'
import { useWidgetsStore } from '../store'

/**
 * Renders admin-configured external panels as sandboxed iframes. The sandbox
 * deliberately omits `allow-top-navigation` and `allow-modals`; external pages
 * never receive credentials or access to the app DOM.
 */
export default function WidgetsPage() {
  const { widgets, loaded, load } = useWidgetsStore()

  useEffect(() => {
    void load()
  }, [load])

  if (loaded && widgets.length === 0) {
    return (
      <Box sx={{ maxWidth: 720, mx: 'auto', mt: 4 }}>
        <Alert severity="info">Виджеты не настроены. Добавьте их в админ-панели (раздел «Виджеты»).</Alert>
      </Box>
    )
  }

  return (
    <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        <WidgetsIcon color="primary" />
        <Typography variant="h6" sx={{ fontWeight: 600 }}>
          Виджеты
        </Typography>
      </Box>

      {widgets.map((widget) => (
        <Paper key={widget.id} variant="outlined" sx={{ overflow: 'hidden' }}>
          <Box
            sx={{
              px: 2,
              py: 1,
              borderBottom: '1px solid',
              borderColor: 'divider',
              display: 'flex',
              alignItems: 'center',
              gap: 1,
            }}
          >
            <Typography sx={{ fontWeight: 600, fontSize: '0.9rem' }}>{widget.title}</Typography>
            <Typography
              component="a"
              href={widget.url}
              target="_blank"
              rel="noopener noreferrer"
              sx={{ fontSize: '0.75rem', color: 'text.secondary', textDecoration: 'none', ml: 'auto' }}
            >
              открыть ↗
            </Typography>
          </Box>
          <Box
            component="iframe"
            title={widget.title}
            src={widget.url}
            sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox"
            referrerPolicy="no-referrer"
            loading="lazy"
            sx={{
              width: '100%',
              height: `${widget.height}px`,
              border: 'none',
              bgcolor: 'background.default',
              display: 'block',
            }}
          />
        </Paper>
      ))}
    </Box>
  )
}
