import { forwardRef } from 'react'
import FullCalendar from '@fullcalendar/react'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import listPlugin from '@fullcalendar/list'
import interactionPlugin from '@fullcalendar/interaction'
import ruLocale from '@fullcalendar/core/locales/ru'
import { Box, CircularProgress, Typography } from '@mui/material'
import type { CalendarItem } from '../types'
import { toFullCalendarEvent } from '../utils'

type ViewName = 'dayGridMonth' | 'timeGridWeek' | 'timeGridDay' | 'listWeek'

interface Props {
  items: CalendarItem[]
  loading?: boolean
  view: ViewName
  height?: string | number
  onDatesSet: (from: string, to: string) => void
  onDateClick: (date: string, allDay: boolean) => void
  onSelect: (start: string, end: string, allDay: boolean) => void
  onEventClick: (item: CalendarItem) => void
  onEventChange: (item: CalendarItem, start: string, end: string) => void
}

const CalendarBoard = forwardRef<FullCalendar, Props>(function CalendarBoard(
  { items, loading, view, height = '100%', onDatesSet, onDateClick, onSelect, onEventClick, onEventChange },
  ref,
) {
  const events = items.map(toFullCalendarEvent)

  return (
    <Box sx={{ position: 'relative', height }}>
      {loading && (
        <Box sx={{ position: 'absolute', top: 8, right: 8, zIndex: 5 }}>
          <CircularProgress size={22} />
        </Box>
      )}
      {items.length === 0 && !loading && (
        <Typography variant="caption" color="text.secondary" sx={{ position: 'absolute', top: 8, left: 8, zIndex: 5 }}>
          Нет событий в выбранном периоде
        </Typography>
      )}
      <FullCalendar
        ref={ref}
        plugins={[dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin]}
        initialView={view}
        key={view}
        locale={ruLocale}
        firstDay={1}
        height={height}
        nowIndicator
        selectable
        selectMirror
        dayMaxEvents
        editable
        eventStartEditable
        eventDurationEditable
        headerToolbar={false}
        events={events}
        datesSet={(arg) => onDatesSet(arg.startStr.slice(0, 10), arg.endStr.slice(0, 10))}
        dateClick={(arg) => onDateClick(arg.dateStr, arg.allDay)}
        select={(arg) => onSelect(arg.startStr, arg.endStr, arg.allDay)}
        eventClick={(arg) => {
          const item = arg.event.extendedProps.item as CalendarItem
          if (item.type === 'event') {
            onEventClick(item)
          } else {
            onEventClick(item)
          }
        }}
        eventChange={(arg) => {
          const item = arg.event.extendedProps.item as CalendarItem
          const start = arg.event.start?.toISOString()
          const end = arg.event.end?.toISOString()
          if (item && start) onEventChange(item, start, end ?? start)
        }}
      />
    </Box>
  )
})

export default CalendarBoard
