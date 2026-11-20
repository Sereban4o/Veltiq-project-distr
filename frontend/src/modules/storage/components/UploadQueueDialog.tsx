import {
  Alert,
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  LinearProgress,
  Typography,
} from '@mui/material'
import type { UploadItem } from '../hooks/useUploadQueue'

interface Props {
  open: boolean
  items: UploadItem[]
  onClose: () => void
  onResolve: (id: string, action: 'overwrite' | 'skip' | 'rename') => void
  onCancel: (id: string) => void
  onClear: () => void
}

function statusLabel(status: UploadItem['status']): string {
  switch (status) {
    case 'queued':
      return 'В очереди'
    case 'uploading':
      return 'Загрузка'
    case 'done':
      return 'Завершено'
    case 'error':
      return 'Ошибка'
    case 'conflict':
      return 'Конфликт'
    case 'canceled':
      return 'Отменено'
    default:
      return ''
  }
}

export default function UploadQueueDialog({ open, items, onClose, onResolve, onCancel, onClear }: Props) {
  const active = items.filter((item) => item.status === 'queued' || item.status === 'uploading').length

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle>Загрузка файлов {active > 0 ? `(${active})` : ''}</DialogTitle>
      <DialogContent dividers>
        {items.length === 0 && <Alert severity="info">Очередь пуста</Alert>}

        {items.map((item) => (
          <Box key={item.id} sx={{ mb: 1.5 }}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <Typography sx={{ flex: 1, fontSize: '0.85rem' }} noWrap title={item.relativePath}>
                {item.relativePath}
              </Typography>
              <Typography sx={{ fontSize: '0.75rem', color: 'text.secondary' }}>{statusLabel(item.status)}</Typography>
            </Box>

            <LinearProgress
              variant="determinate"
              value={item.status === 'uploading' ? item.progress : item.status === 'done' ? 100 : 0}
              color={item.status === 'error' || item.status === 'conflict' ? 'error' : 'primary'}
              sx={{ my: 0.5 }}
            />

            {item.error && <Typography sx={{ fontSize: '0.75rem', color: 'error.main' }}>{item.error}</Typography>}

            {item.status === 'conflict' && (
              <Box sx={{ display: 'flex', gap: 1, mt: 0.5 }}>
                <Button size="small" onClick={() => onResolve(item.id, 'overwrite')} sx={{ textTransform: 'none' }}>
                  Перезаписать
                </Button>
                <Button size="small" onClick={() => onResolve(item.id, 'rename')} sx={{ textTransform: 'none' }}>
                  Переименовать
                </Button>
                <Button
                  size="small"
                  color="inherit"
                  onClick={() => onResolve(item.id, 'skip')}
                  sx={{ textTransform: 'none' }}
                >
                  Пропустить
                </Button>
              </Box>
            )}

            {item.status === 'error' && (
              <Box sx={{ display: 'flex', gap: 1, mt: 0.5 }}>
                <Button size="small" onClick={() => onResolve(item.id, 'overwrite')} sx={{ textTransform: 'none' }}>
                  Повторить (перезапись)
                </Button>
              </Box>
            )}

            {(item.status === 'queued' || item.status === 'uploading') && (
              <Button
                size="small"
                color="inherit"
                onClick={() => onCancel(item.id)}
                sx={{ textTransform: 'none', mt: 0.5 }}
              >
                Отменить
              </Button>
            )}
          </Box>
        ))}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClear} sx={{ textTransform: 'none' }}>
          Очистить завершённые
        </Button>
        <Button onClick={onClose} variant="contained" sx={{ textTransform: 'none' }}>
          Закрыть
        </Button>
      </DialogActions>
    </Dialog>
  )
}
