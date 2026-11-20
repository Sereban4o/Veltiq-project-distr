import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  IconButton,
  Stack,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import { Delete as DeleteIcon, Sync as SyncIcon, UploadFile as UploadIcon } from '@mui/icons-material'
import { calendarApi } from '../api'
import type { CalendarFeed } from '../types'

interface Props {
  open: boolean
  onClose: () => void
}

const DEFAULT_FORM = { name: '', url: '', color: '#7C3AED', sync_interval_minutes: 60 }

export default function FeedsPanel({ open, onClose }: Props) {
  const queryClient = useQueryClient()
  const [form, setForm] = useState({ ...DEFAULT_FORM })
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  const { data: feeds = [], isLoading } = useQuery({
    queryKey: ['calendar-feeds'],
    queryFn: () => calendarApi.feeds(),
    enabled: open,
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['calendar-feeds'] })
    queryClient.invalidateQueries({ queryKey: ['calendar'] })
  }

  const createFeed = useMutation({
    mutationFn: () => calendarApi.createFeed(form),
    onSuccess: () => {
      setForm({ ...DEFAULT_FORM })
      setError('')
      invalidate()
    },
    onError: (err: any) => setError(err?.response?.data?.message || 'Не удалось добавить подписку'),
  })

  const deleteFeed = useMutation({
    mutationFn: (feed: CalendarFeed) => calendarApi.deleteFeed(feed.id),
    onSuccess: invalidate,
  })

  const syncFeed = useMutation({
    mutationFn: (feed: CalendarFeed) => calendarApi.syncFeed(feed.id),
    onSuccess: () => {
      setMessage('Синхронизация запущена')
      setTimeout(() => invalidate(), 1500)
    },
  })

  const importIcs = useMutation({
    mutationFn: (file: File) => calendarApi.importIcs(file),
    onSuccess: (result) => {
      setMessage(`Импортировано: ${result.imported}, обновлено: ${result.updated}, пропущено: ${result.skipped}`)
      invalidate()
    },
    onError: (err: any) => setError(err?.response?.data?.message || 'Не удалось импортировать файл'),
  })

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Внешние календари (.ics)</DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2}>
          {message && (
            <Alert severity="success" onClose={() => setMessage('')}>
              {message}
            </Alert>
          )}
          {error && (
            <Alert severity="error" onClose={() => setError('')}>
              {error}
            </Alert>
          )}

          <Typography variant="subtitle2">Подписки</Typography>
          {isLoading ? (
            <CircularProgress size={20} />
          ) : feeds.length === 0 ? (
            <Typography variant="body2" color="text.secondary">
              Подписок нет
            </Typography>
          ) : (
            <Stack divider={<Divider />} spacing={1}>
              {feeds.map((feed) => (
                <Box key={feed.id} sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                  <Box
                    sx={{ width: 10, height: 10, borderRadius: '50%', bgcolor: feed.color || '#7C3AED', flexShrink: 0 }}
                  />
                  <Box sx={{ flexGrow: 1, minWidth: 0 }}>
                    <Typography variant="body2" noWrap>
                      {feed.name}
                    </Typography>
                    <Typography variant="caption" color="text.secondary" noWrap display="block">
                      {feed.url}
                    </Typography>
                    {feed.last_synced_at && (
                      <Typography variant="caption" color="text.secondary">
                        Синхронизировано: {new Date(feed.last_synced_at).toLocaleString()}
                      </Typography>
                    )}
                    {feed.last_error && (
                      <Typography variant="caption" color="error" display="block">
                        {feed.last_error}
                      </Typography>
                    )}
                  </Box>
                  <Tooltip title="Синхронизировать">
                    <IconButton size="small" onClick={() => syncFeed.mutate(feed)} disabled={syncFeed.isPending}>
                      <SyncIcon fontSize="small" />
                    </IconButton>
                  </Tooltip>
                  <Tooltip title="Удалить">
                    <IconButton size="small" color="error" onClick={() => deleteFeed.mutate(feed)}>
                      <DeleteIcon fontSize="small" />
                    </IconButton>
                  </Tooltip>
                </Box>
              ))}
            </Stack>
          )}

          <Divider />

          <Typography variant="subtitle2">Новая подписка</Typography>
          <TextField
            size="small"
            label="Название"
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
          />
          <TextField
            size="small"
            label="URL (.ics)"
            placeholder="https://example.com/calendar.ics"
            value={form.url}
            onChange={(e) => setForm({ ...form, url: e.target.value })}
          />
          <TextField
            size="small"
            type="number"
            label="Интервал синхронизации (мин)"
            value={form.sync_interval_minutes}
            onChange={(e) => setForm({ ...form, sync_interval_minutes: Number(e.target.value) || 60 })}
          />
          <Button
            variant="outlined"
            onClick={() => createFeed.mutate()}
            disabled={!form.name.trim() || !form.url.trim() || createFeed.isPending}
          >
            Добавить подписку
          </Button>

          <Divider />

          <Typography variant="subtitle2">Импорт из файла</Typography>
          <Button component="label" startIcon={<UploadIcon />} variant="outlined" disabled={importIcs.isPending}>
            Выбрать .ics
            <input
              type="file"
              hidden
              accept=".ics,text/calendar"
              onChange={(e) => {
                const file = e.target.files?.[0]
                if (file) importIcs.mutate(file)
                e.currentTarget.value = ''
              }}
            />
          </Button>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Закрыть</Button>
      </DialogActions>
    </Dialog>
  )
}
