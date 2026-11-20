import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import FullCalendar from '@fullcalendar/react'
import { Box, Button, MenuItem, Select, Stack, Typography } from '@mui/material'
import AddIcon from '@mui/icons-material/Add'
import DownloadIcon from '@mui/icons-material/Download'
import EventNoteIcon from '@mui/icons-material/EventNote'
import { format, startOfMonth, endOfMonth } from 'date-fns'
import { calendarApi } from '../api'
import type { CalendarEvent, CalendarItem } from '../types'
import { isoToLocalInput } from '../utils'
import CalendarBoard from '../components/CalendarBoard'
import EventDialog from '../components/EventDialog'
import EventDetailsDialog from '../components/EventDetailsDialog'
import FeedsPanel from '../components/FeedsPanel'

type ViewName = 'dayGridMonth' | 'timeGridWeek' | 'timeGridDay' | 'listWeek'

export default function CalendarPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const calendarRef = useRef<FullCalendar>(null)
  const [searchParams] = useSearchParams()

  const [view, setView] = useState<ViewName>('dayGridMonth')
  const [range, setRange] = useState(() => ({
    from: format(startOfMonth(new Date()), 'yyyy-MM-dd'),
    to: format(endOfMonth(new Date()), 'yyyy-MM-dd'),
  }))

  const [detailsOpen, setDetailsOpen] = useState(false)
  const [detailsItem, setDetailsItem] = useState<CalendarItem | null>(null)

  const [dialogOpen, setDialogOpen] = useState(false)
  const [dialogInitial, setDialogInitial] = useState<CalendarEvent | null>(null)
  const [dialogDate, setDialogDate] = useState<string | null>(null)
  const [dialogStart, setDialogStart] = useState<string | null>(null)

  const [feedsOpen, setFeedsOpen] = useState(false)

  const { data, isFetching } = useQuery({
    queryKey: ['calendar', range.from, range.to],
    queryFn: () => calendarApi.range(range.from, range.to),
  })

  const openCreate = useCallback((date?: string | null, start?: string | null) => {
    setDialogInitial(null)
    setDialogDate(date ?? null)
    setDialogStart(start ?? null)
    setDialogOpen(true)
  }, [])

  // Deep links: ?date=yyyy-MM-dd positions the board, ?event=<id> opens details.
  useEffect(() => {
    const date = searchParams.get('date')
    if (date) {
      const api = calendarRef.current?.getApi()
      if (api) api.gotoDate(date)
    }
  }, [searchParams])

  useEffect(() => {
    const eventId = searchParams.get('event')
    if (!eventId) return

    let cancelled = false
    calendarApi
      .event(Number(eventId))
      .then((event) => {
        if (cancelled) return
        setDetailsItem({
          type: 'event',
          id: event.id,
          title: event.title,
          date: event.event_date || event.starts_at.slice(0, 10),
          meta: { event, occurrence_start: event.starts_at, recurring: !!event.rrule },
          editable: event.source !== 'feed' && event.is_organizer !== false,
        })
        setDetailsOpen(true)
      })
      .catch(() => undefined)

    return () => {
      cancelled = true
    }
  }, [searchParams])

  const handleDatesSet = useCallback((from: string, to: string) => {
    setRange((prev) => (prev.from === from && prev.to === to ? prev : { from, to }))
  }, [])

  const handleDateClick = useCallback(
    (date: string, allDay: boolean) => {
      openCreate(date.slice(0, 10), allDay ? `${date.slice(0, 10)}T09:00` : isoToLocalInput(date))
    },
    [openCreate],
  )

  const handleSelect = useCallback(
    (start: string, _end: string, allDay: boolean) => {
      openCreate(start.slice(0, 10), allDay ? `${start.slice(0, 10)}T09:00` : isoToLocalInput(start))
    },
    [openCreate],
  )

  const handleEventClick = useCallback(
    (item: CalendarItem) => {
      if (item.type === 'event') {
        setDetailsItem(item)
        setDetailsOpen(true)
      } else if (item.type === 'task') {
        navigate(`/issues/${item.id}`)
      } else if (item.type === 'deal') {
        navigate('/crm', { state: { section: 'deals', dealId: item.id } })
      }
    },
    [navigate],
  )

  const handleEventChange = useCallback(
    (item: CalendarItem, start: string, end: string) => {
      const eventId = item.meta?.event_id as number | undefined
      const editable = item.editable && !item.meta?.recurring && !item.meta?.all_day

      if (eventId && editable) {
        calendarApi
          .updateEvent(eventId, { starts_at: start, ends_at: end })
          .finally(() => queryClient.invalidateQueries({ queryKey: ['calendar'] }))
      } else {
        // Revert visual change for read-only / recurring events.
        queryClient.invalidateQueries({ queryKey: ['calendar'] })
      }
    },
    [queryClient],
  )

  const changeView = (next: ViewName) => {
    setView(next)
    calendarRef.current?.getApi().changeView(next)
  }

  return (
    <Box>
      <Stack direction="row" spacing={1.5} alignItems="center" sx={{ mb: 2, flexWrap: 'wrap' }} useFlexGap>
        <Typography variant="h5" sx={{ mr: 1 }}>
          Календарь
        </Typography>
        <Button variant="contained" startIcon={<AddIcon />} onClick={() => openCreate()}>
          Новое событие
        </Button>
        <Select size="small" value={view} onChange={(e) => changeView(e.target.value as ViewName)}>
          <MenuItem value="dayGridMonth">Месяц</MenuItem>
          <MenuItem value="timeGridWeek">Неделя</MenuItem>
          <MenuItem value="timeGridDay">День</MenuItem>
          <MenuItem value="listWeek">Список</MenuItem>
        </Select>
        <Box sx={{ flexGrow: 1 }} />
        <Button startIcon={<DownloadIcon />} onClick={() => calendarApi.downloadRangeIcs(range.from, range.to)}>
          Экспорт .ics
        </Button>
        <Button startIcon={<EventNoteIcon />} onClick={() => setFeedsOpen(true)}>
          Подписки
        </Button>
      </Stack>

      <Box
        sx={{
          height: 'calc(100vh - 220px)',
          bgcolor: 'background.paper',
          borderRadius: 1,
          p: 1.5,
          '& .fc-event-declined': { textDecoration: 'line-through', opacity: 0.65 },
          '& .fc-event-tentative': { opacity: 0.75 },
        }}
      >
        <CalendarBoard
          ref={calendarRef}
          items={data?.items ?? []}
          loading={isFetching}
          view={view}
          onDatesSet={handleDatesSet}
          onDateClick={handleDateClick}
          onSelect={handleSelect}
          onEventClick={handleEventClick}
          onEventChange={handleEventChange}
        />
      </Box>

      <EventDialog
        open={dialogOpen}
        initial={dialogInitial}
        defaultDate={dialogDate}
        defaultStart={dialogStart}
        onClose={() => setDialogOpen(false)}
      />

      <EventDetailsDialog
        open={detailsOpen}
        item={detailsItem}
        onClose={() => {
          setDetailsOpen(false)
          setDetailsItem(null)
        }}
      />

      <FeedsPanel open={feedsOpen} onClose={() => setFeedsOpen(false)} />
    </Box>
  )
}
