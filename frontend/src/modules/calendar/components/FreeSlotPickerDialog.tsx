import { useEffect, useState } from 'react'
import {
  Alert,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  List,
  ListItemButton,
  ListItemText,
  TextField,
  Typography,
} from '@mui/material'
import { calendarApi, type FreeSlot } from '../api'

interface FreeSlotPickerDialogProps {
  open: boolean
  /** Bare date (yyyy-MM-dd) to search within. */
  date: string
  durationMinutes: number
  userIds: number[]
  onClose: () => void
  onPick: (startIso: string, endIso: string) => void
}

export default function FreeSlotPickerDialog({
  open,
  date,
  durationMinutes,
  userIds,
  onClose,
  onPick,
}: FreeSlotPickerDialogProps) {
  const [duration, setDuration] = useState(durationMinutes)
  const [slots, setSlots] = useState<FreeSlot[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  const userIdsKey = userIds.join(',')

  useEffect(() => {
    if (open) setDuration(durationMinutes)
  }, [open, durationMinutes])

  useEffect(() => {
    if (!open) return
    let cancelled = false

    setLoading(true)
    setError('')
    calendarApi
      .freeSlots({
        date,
        duration,
        user_ids: userIdsKey ? userIdsKey.split(',').map(Number) : [],
      })
      .then((response) => {
        if (!cancelled) setSlots(response.slots)
      })
      .catch(() => {
        if (!cancelled) setError('Не удалось получить свободное время')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [open, date, duration, userIdsKey])

  const format = (iso: string) => new Date(iso).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="xs">
      <DialogTitle>Свободное время — {date}</DialogTitle>
      <DialogContent>
        {error && (
          <Alert severity="error" sx={{ mb: 2 }}>
            {error}
          </Alert>
        )}

        <TextField
          type="number"
          size="small"
          label="Длительность, мин"
          value={duration}
          onChange={(e) => setDuration(Math.max(15, Number(e.target.value) || 15))}
          sx={{ mb: 2 }}
        />

        {loading && (
          <Typography variant="body2" color="text.secondary">
            Загрузка…
          </Typography>
        )}

        {!loading && slots.length === 0 && (
          <Typography variant="body2" color="text.secondary">
            Нет свободных слотов
          </Typography>
        )}

        <List dense sx={{ maxHeight: 320, overflow: 'auto' }}>
          {slots.map((slot) => (
            <ListItemButton
              key={slot.start}
              onClick={() => {
                onPick(slot.start, slot.end)
                onClose()
              }}
            >
              <ListItemText primary={`${format(slot.start)} – ${format(slot.end)}`} />
            </ListItemButton>
          ))}
        </List>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Закрыть</Button>
      </DialogActions>
    </Dialog>
  )
}
