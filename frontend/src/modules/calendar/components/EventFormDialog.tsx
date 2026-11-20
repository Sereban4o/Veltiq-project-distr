import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, TextField, Typography } from '@mui/material'
import { calendarApi } from '../api'
import type { CalendarEvent } from '../types'

const COLOR_SWATCHES = ['#0052CC', '#36B37E', '#FFAB00', '#FF5630', '#7C3AED']

export type EventFormInitial = Pick<CalendarEvent, 'id' | 'title'> & Partial<CalendarEvent>

interface Props {
  open: boolean
  date: string
  initial?: EventFormInitial | null
  onClose: () => void
}

export default function EventFormDialog({ open, date, initial, onClose }: Props) {
  const queryClient = useQueryClient()
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [eventDate, setEventDate] = useState(date)
  const [color, setColor] = useState<string | null>(null)

  useEffect(() => {
    if (open) {
      setTitle(initial?.title ?? '')
      setDescription(initial?.description ?? '')
      setEventDate(initial?.event_date ?? date)
      setColor(initial?.color ?? null)
    }
  }, [open, initial, date])

  const mutation = useMutation({
    mutationFn: async () => {
      const payload = {
        title: title.trim(),
        description: description.trim() || null,
        event_date: eventDate,
        color,
      }

      return initial ? calendarApi.updateEvent(initial.id, payload) : calendarApi.createEvent(payload)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['calendar'] })
      onClose()
    },
  })

  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle>{initial ? 'Редактировать событие' : 'Новое событие'}</DialogTitle>
      <DialogContent sx={{ pt: 3 }}>
        <TextField
          autoFocus
          fullWidth
          label="Название"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          sx={{ mb: 2 }}
        />
        <TextField
          fullWidth
          multiline
          minRows={2}
          label="Описание"
          value={description}
          onChange={(e) => setDescription(e.target.value)}
          sx={{ mb: 2 }}
        />
        <TextField
          fullWidth
          type="date"
          label="Дата"
          value={eventDate}
          onChange={(e) => setEventDate(e.target.value)}
          InputLabelProps={{ shrink: true }}
          sx={{ mb: 2 }}
        />
        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.75 }}>
          Цвет
        </Typography>
        <Box sx={{ display: 'flex', gap: 1 }}>
          {COLOR_SWATCHES.map((swatch) => (
            <Box
              key={swatch}
              onClick={() => setColor(swatch)}
              sx={{
                width: 26,
                height: 26,
                borderRadius: '50%',
                bgcolor: swatch,
                cursor: 'pointer',
                border: color === swatch ? '2px solid' : '2px solid transparent',
                borderColor: color === swatch ? 'text.primary' : 'transparent',
              }}
            />
          ))}
        </Box>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Отмена</Button>
        <Button variant="contained" onClick={() => mutation.mutate()} disabled={!title.trim() || mutation.isPending}>
          {initial ? 'Сохранить' : 'Создать'}
        </Button>
      </DialogActions>
    </Dialog>
  )
}
