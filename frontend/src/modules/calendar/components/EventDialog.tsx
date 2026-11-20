import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  MenuItem,
  Switch,
  TextField,
  Typography,
} from '@mui/material'
import DownloadIcon from '@mui/icons-material/Download'
import { calendarApi } from '../api'
import type { CalendarEvent, CalendarEventInput } from '../types'
import { browserTimezone, isoToLocalDateInput, isoToLocalInput, localInputToIso } from '../utils'
import RecurrenceEditor from './RecurrenceEditor'
import ParticipantsEditor from './ParticipantsEditor'
import LinkEditor from './LinkEditor'
import FreeSlotPickerDialog from './FreeSlotPickerDialog'

const COLOR_SWATCHES = ['#0052CC', '#36B37E', '#FFAB00', '#FF5630', '#7C3AED']

const FALLBACK_TIMEZONES = [
  'UTC',
  'Europe/Moscow',
  'Europe/Kaliningrad',
  'Europe/Kyiv',
  'Europe/Berlin',
  'Asia/Almaty',
  'Asia/Tbilisi',
  'Asia/Yerevan',
  'Asia/Dubai',
  'America/New_York',
  'America/Los_Angeles',
]

interface Props {
  open: boolean
  initial?: CalendarEvent | null
  defaultDate?: string | null
  defaultStart?: string | null
  defaultIssueIds?: number[]
  defaultDealIds?: number[]
  onClose: () => void
}

export default function EventDialog({
  open,
  initial,
  defaultDate,
  defaultStart,
  defaultIssueIds,
  defaultDealIds,
  onClose,
}: Props) {
  const queryClient = useQueryClient()

  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [location, setLocation] = useState('')
  const [allDay, setAllDay] = useState(false)
  const [startLocal, setStartLocal] = useState('')
  const [endLocal, setEndLocal] = useState('')
  const [timezone, setTimezone] = useState(browserTimezone())
  const [color, setColor] = useState<string | null>(null)
  const [visibility, setVisibility] = useState<'private' | 'public'>('private')
  const [rrule, setRrule] = useState<string | null>(null)
  const [userIds, setUserIds] = useState<number[]>([])
  const [emails, setEmails] = useState<string[]>([])
  const [issueIds, setIssueIds] = useState<number[]>([])
  const [dealIds, setDealIds] = useState<number[]>([])
  const [error, setError] = useState('')
  const [freeSlotsOpen, setFreeSlotsOpen] = useState(false)

  const timezones = useMemo(() => {
    try {
      const supported = (Intl as any).supportedValuesOf?.('timeZone')
      return Array.isArray(supported) && supported.length ? supported : FALLBACK_TIMEZONES
    } catch {
      return FALLBACK_TIMEZONES
    }
  }, [])

  const isOrganizer = !initial || initial.is_organizer !== false

  const durationMinutes = (() => {
    const start = new Date(startLocal)
    const end = new Date(endLocal)
    if (startLocal && endLocal && !Number.isNaN(start.getTime()) && !Number.isNaN(end.getTime()) && end > start) {
      return Math.max(15, Math.round((end.getTime() - start.getTime()) / 60000))
    }
    return 60
  })()

  useEffect(() => {
    if (!open) return

    setError('')

    if (initial) {
      setTitle(initial.title)
      setDescription(initial.description ?? '')
      setLocation(initial.location ?? '')
      setAllDay(!!initial.all_day)
      setStartLocal(initial.all_day ? isoToLocalDateInput(initial.starts_at) : isoToLocalInput(initial.starts_at))
      setEndLocal(initial.all_day ? isoToLocalDateInput(initial.ends_at) : isoToLocalInput(initial.ends_at))
      setTimezone(initial.timezone || browserTimezone())
      setColor(initial.color ?? null)
      setVisibility(initial.visibility ?? 'private')
      setRrule(initial.rrule ?? null)
      setUserIds((initial.participants ?? []).filter((p) => p.user_id).map((p) => p.user_id as number))
      setEmails((initial.participants ?? []).filter((p) => p.email).map((p) => p.email as string))
      setIssueIds((initial.links ?? []).filter((l) => l.issue_id).map((l) => l.issue_id as number))
      setDealIds((initial.links ?? []).filter((l) => l.deal_id).map((l) => l.deal_id as number))
    } else {
      setTitle('')
      setDescription('')
      setLocation('')
      setAllDay(false)
      const start = defaultStart ?? (defaultDate ? `${defaultDate}T09:00` : '')
      setStartLocal(start)
      setEndLocal(defaultStart ?? (defaultDate ? `${defaultDate}T10:00` : ''))
      setTimezone(browserTimezone())
      setColor(null)
      setVisibility('private')
      setRrule(null)
      setUserIds([])
      setEmails([])
      setIssueIds(defaultIssueIds ?? [])
      setDealIds(defaultDealIds ?? [])
    }
  }, [open, initial, defaultDate, defaultStart, defaultIssueIds, defaultDealIds])

  const mutation = useMutation({
    mutationFn: async () => {
      const startsAt = allDay ? localInputToIso(`${startLocal}T00:00`) : localInputToIso(startLocal)

      if (!startsAt) throw new Error('Укажите дату начала')

      const endsAt = allDay ? (endLocal ? localInputToIso(`${endLocal}T23:59`) : null) : localInputToIso(endLocal)

      const payload: CalendarEventInput = {
        title: title.trim(),
        description: description.trim() || null,
        location: location.trim() || null,
        starts_at: startsAt,
        ends_at: endsAt,
        all_day: allDay,
        timezone,
        rrule,
        exdates: initial?.exdates ?? null,
        color,
        visibility,
        issue_ids: issueIds,
        deal_ids: dealIds,
      }

      if (!initial) {
        const created = await calendarApi.createEvent(payload)
        if (userIds.length || emails.length) {
          await calendarApi.addParticipants(created.id, { user_ids: userIds, emails })
        }
        return created
      }

      const updated = await calendarApi.updateEvent(initial.id, payload)

      if (isOrganizer) {
        const initialUserIds = (initial.participants ?? []).filter((p) => p.user_id).map((p) => p.user_id as number)
        const initialEmails = (initial.participants ?? []).filter((p) => p.email).map((p) => p.email as string)

        const removed = (initial.participants ?? []).filter(
          (p) => (p.user_id && !userIds.includes(p.user_id)) || (p.email && !emails.includes(p.email)),
        )
        for (const attendee of removed) {
          await calendarApi.removeParticipant(initial.id, attendee.id)
        }

        const newUsers = userIds.filter((id) => !initialUserIds.includes(id))
        const newEmails = emails.filter((email) => !initialEmails.includes(email))
        if (newUsers.length || newEmails.length) {
          await calendarApi.addParticipants(initial.id, { user_ids: newUsers, emails: newEmails })
        }
      }

      return updated
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['calendar'] })
      queryClient.invalidateQueries({ queryKey: ['calendar-events'] })
      onClose()
    },
    onError: (err: any) => {
      const message = err?.response?.data?.message || err?.message || 'Не удалось сохранить событие'
      setError(message)
    },
  })

  const toggleAllDay = (next: boolean) => {
    setAllDay(next)
    if (next) {
      setStartLocal(startLocal.slice(0, 10))
      setEndLocal(endLocal.slice(0, 10) || startLocal.slice(0, 10))
    } else if (startLocal.length === 10) {
      setStartLocal(`${startLocal}T09:00`)
      setEndLocal(`${startLocal}T10:00`)
    }
  }

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>{initial ? 'Редактировать событие' : 'Новое событие'}</DialogTitle>
      <DialogContent sx={{ pt: 2, display: 'flex', flexDirection: 'column', gap: 2 }}>
        <TextField autoFocus fullWidth label="Название" value={title} onChange={(e) => setTitle(e.target.value)} />

        <FormControlLabel
          control={<Switch checked={allDay} onChange={(e) => toggleAllDay(e.target.checked)} />}
          label="Весь день"
        />

        {allDay ? (
          <Box sx={{ display: 'flex', gap: 2 }}>
            <TextField
              fullWidth
              type="date"
              label="Начало"
              value={startLocal.slice(0, 10)}
              InputLabelProps={{ shrink: true }}
              onChange={(e) => setStartLocal(e.target.value)}
            />
            <TextField
              fullWidth
              type="date"
              label="Окончание"
              value={endLocal.slice(0, 10)}
              InputLabelProps={{ shrink: true }}
              onChange={(e) => setEndLocal(e.target.value)}
            />
          </Box>
        ) : (
          <Box sx={{ display: 'flex', gap: 2 }}>
            <TextField
              fullWidth
              type="datetime-local"
              label="Начало"
              value={startLocal}
              InputLabelProps={{ shrink: true }}
              onChange={(e) => setStartLocal(e.target.value)}
            />
            <TextField
              fullWidth
              type="datetime-local"
              label="Окончание"
              value={endLocal}
              InputLabelProps={{ shrink: true }}
              onChange={(e) => setEndLocal(e.target.value)}
            />
          </Box>
        )}

        <TextField fullWidth select label="Часовой пояс" value={timezone} onChange={(e) => setTimezone(e.target.value)}>
          {timezones.map((zone: string) => (
            <MenuItem key={zone} value={zone}>
              {zone}
            </MenuItem>
          ))}
        </TextField>

        <TextField
          fullWidth
          multiline
          minRows={2}
          label="Описание"
          value={description}
          onChange={(e) => setDescription(e.target.value)}
        />

        <TextField fullWidth label="Место" value={location} onChange={(e) => setLocation(e.target.value)} />

        <Box>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.5 }}>
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
                  border: '2px solid',
                  borderColor: color === swatch ? 'text.primary' : 'transparent',
                }}
              />
            ))}
          </Box>
        </Box>

        <TextField
          fullWidth
          select
          label="Доступность"
          value={visibility}
          onChange={(e) => setVisibility(e.target.value as 'private' | 'public')}
        >
          <MenuItem value="private">Приватное</MenuItem>
          <MenuItem value="public">Публичное (видят все)</MenuItem>
        </TextField>

        <Box>
          <Typography variant="subtitle2" sx={{ mb: 1 }}>
            Повторение
          </Typography>
          <RecurrenceEditor value={rrule} onChange={setRrule} />
        </Box>

        <Box>
          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1 }}>
            <Typography variant="subtitle2">Участники</Typography>
            <Button size="small" onClick={() => setFreeSlotsOpen(true)}>
              Найти время
            </Button>
          </Box>
          <ParticipantsEditor
            userValues={userIds}
            emailValues={emails}
            onChange={(next) => {
              setUserIds(next.user_ids)
              setEmails(next.emails)
            }}
            disabled={!isOrganizer}
          />
        </Box>

        <Box>
          <Typography variant="subtitle2" sx={{ mb: 1 }}>
            Связанные записи
          </Typography>
          <LinkEditor
            issueIds={issueIds}
            dealIds={dealIds}
            onChange={(next) => {
              setIssueIds(next.issue_ids)
              setDealIds(next.deal_ids)
            }}
            disabled={!isOrganizer}
          />
        </Box>

        {error && <Alert severity="error">{error}</Alert>}

        <FreeSlotPickerDialog
          open={freeSlotsOpen}
          date={startLocal.slice(0, 10) || isoToLocalDateInput(new Date().toISOString())}
          durationMinutes={durationMinutes}
          userIds={userIds}
          onClose={() => setFreeSlotsOpen(false)}
          onPick={(startIso, endIso) => {
            setAllDay(false)
            setStartLocal(isoToLocalInput(startIso))
            setEndLocal(isoToLocalInput(endIso))
          }}
        />
      </DialogContent>
      <DialogActions>
        {initial && (
          <Button startIcon={<DownloadIcon />} onClick={() => calendarApi.downloadEventIcs(initial.id)}>
            Скачать .ics
          </Button>
        )}
        <Box sx={{ flexGrow: 1 }} />
        <Button onClick={onClose}>Отмена</Button>
        <Button
          variant="contained"
          onClick={() => mutation.mutate()}
          disabled={!title.trim() || mutation.isPending}
          startIcon={mutation.isPending ? <CircularProgress size={16} color="inherit" /> : undefined}
        >
          {initial ? 'Сохранить' : 'Создать'}
        </Button>
      </DialogActions>
    </Dialog>
  )
}
