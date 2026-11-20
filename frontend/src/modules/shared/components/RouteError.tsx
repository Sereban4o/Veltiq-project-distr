import { Box, Button, Paper, Stack, Typography } from '@mui/material'
import { Refresh as RefreshIcon, Home as HomeIcon } from '@mui/icons-material'
import { useNavigate, useRouteError } from 'react-router-dom'

/**
 * Friendly fallback for unhandled route/render errors.
 *
 * Prevents the raw "Unexpected Application Error!" developer screen from
 * showing to users, and gives a one-click way to recover (a reload is enough
 * for the overwhelming majority of these cases).
 */
export default function RouteError() {
  const error = useRouteError() as any
  const navigate = useNavigate()

  const message = error?.statusText || error?.message || (typeof error === 'string' ? error : 'Неизвестная ошибка')

  return (
    <Box
      sx={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        p: 3,
        bgcolor: 'background.default',
      }}
    >
      <Paper sx={{ p: 4, maxWidth: 560, width: '100%' }} elevation={3}>
        <Typography sx={{ fontSize: '1.25rem', fontWeight: 700, mb: 1 }}>Что-то пошло не так</Typography>
        <Typography sx={{ color: 'text.secondary', mb: 2 }}>
          Страница не смогла загрузиться. Обычно помогает обновление — данные не потеряны.
        </Typography>
        <Box
          component="pre"
          sx={{
            p: 1.5,
            mb: 3,
            borderRadius: 1,
            bgcolor: 'action.hover',
            color: 'text.secondary',
            fontSize: '0.78rem',
            whiteSpace: 'pre-wrap',
            wordBreak: 'break-word',
            maxHeight: 160,
            overflow: 'auto',
          }}
        >
          {String(message)}
        </Box>
        <Stack direction="row" spacing={1.5}>
          <Button
            variant="contained"
            startIcon={<RefreshIcon />}
            onClick={() => window.location.reload()}
            sx={{ textTransform: 'none' }}
          >
            Обновить страницу
          </Button>
          <Button
            variant="outlined"
            startIcon={<HomeIcon />}
            onClick={() => navigate('/')}
            sx={{ textTransform: 'none' }}
          >
            На главную
          </Button>
        </Stack>
      </Paper>
    </Box>
  )
}
