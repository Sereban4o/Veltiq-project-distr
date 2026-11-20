import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Box, CircularProgress, IconButton, Tooltip, Typography } from '@mui/material'
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft'
import ChevronRightIcon from '@mui/icons-material/ChevronRight'
import {
  addMonths,
  subMonths,
  startOfMonth,
  endOfMonth,
  startOfWeek,
  endOfWeek,
  eachDayOfInterval,
  isSameMonth,
  isSameDay,
  format,
} from 'date-fns'
import { ru } from 'date-fns/locale'
import { calendarApi } from '../api'
import type { CalendarItem } from '../types'
import { itemColor } from '../utils'
import CalendarDayDialog from './CalendarDayDialog'

const WEEKDAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс']

export default function SidebarCalendar() {
  const [viewMonth, setViewMonth] = useState(() => startOfMonth(new Date()))
  const [selectedDate, setSelectedDate] = useState<string | null>(null)

  const from = format(startOfMonth(viewMonth), 'yyyy-MM-dd')
  const to = format(endOfMonth(viewMonth), 'yyyy-MM-dd')

  const { data, isLoading } = useQuery({
    queryKey: ['calendar', from, to],
    queryFn: () => calendarApi.range(from, to),
  })

  const itemsByDate = useMemo(() => {
    const map = new Map<string, CalendarItem[]>()
    for (const item of data?.items ?? []) {
      const list = map.get(item.date) ?? []
      list.push(item)
      map.set(item.date, list)
    }
    return map
  }, [data])

  const days = useMemo(
    () =>
      eachDayOfInterval({
        start: startOfWeek(startOfMonth(viewMonth), { weekStartsOn: 1 }),
        end: endOfWeek(endOfMonth(viewMonth), { weekStartsOn: 1 }),
      }),
    [viewMonth],
  )

  const today = new Date()
  const selectedItems = selectedDate ? (itemsByDate.get(selectedDate) ?? []) : []

  return (
    <Box sx={{ px: 1.5, pt: 1.5, pb: 1 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1 }}>
        <Typography sx={{ color: '#ffffff', fontWeight: 600, fontSize: '0.85rem' }}>Календарь</Typography>
        <Box sx={{ display: 'flex', alignItems: 'center' }}>
          <Tooltip title="Предыдущий месяц" arrow>
            <IconButton
              size="small"
              onClick={() => setViewMonth((m) => subMonths(m, 1))}
              sx={{ color: 'rgba(255,255,255,0.7)', p: 0.4 }}
            >
              <ChevronLeftIcon fontSize="small" />
            </IconButton>
          </Tooltip>
          <Tooltip title="Следующий месяц" arrow>
            <IconButton
              size="small"
              onClick={() => setViewMonth((m) => addMonths(m, 1))}
              sx={{ color: 'rgba(255,255,255,0.7)', p: 0.4 }}
            >
              <ChevronRightIcon fontSize="small" />
            </IconButton>
          </Tooltip>
        </Box>
      </Box>

      <Typography
        sx={{
          color: 'rgba(255,255,255,0.85)',
          fontSize: '0.78rem',
          fontWeight: 500,
          textAlign: 'center',
          mb: 0.75,
          textTransform: 'capitalize',
        }}
      >
        {format(viewMonth, 'LLLL yyyy', { locale: ru })}
      </Typography>

      {isLoading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 3 }}>
          <CircularProgress size={20} sx={{ color: 'rgba(255,255,255,0.6)' }} />
        </Box>
      ) : (
        <>
          <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', mb: 0.5 }}>
            {WEEKDAYS.map((day) => (
              <Typography
                key={day}
                sx={{ color: 'rgba(255,255,255,0.45)', fontSize: '0.62rem', textAlign: 'center', fontWeight: 600 }}
              >
                {day}
              </Typography>
            ))}
          </Box>

          <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', rowGap: 0.25 }}>
            {days.map((day) => {
              const dateKey = format(day, 'yyyy-MM-dd')
              const items = itemsByDate.get(dateKey) ?? []
              const inMonth = isSameMonth(day, viewMonth)
              const isToday = isSameDay(day, today)

              return (
                <Box key={dateKey} sx={{ display: 'flex', justifyContent: 'center' }}>
                  <Box
                    onClick={() => setSelectedDate(dateKey)}
                    sx={{
                      width: 28,
                      height: 30,
                      borderRadius: 1.5,
                      cursor: 'pointer',
                      display: 'flex',
                      flexDirection: 'column',
                      alignItems: 'center',
                      justifyContent: 'center',
                      border: isToday ? '1px solid rgba(255,255,255,0.6)' : '1px solid transparent',
                      color: inMonth ? '#ffffff' : 'rgba(255,255,255,0.3)',
                      transition: 'background-color 0.15s',
                      '&:hover': { backgroundColor: 'rgba(255,255,255,0.12)' },
                    }}
                  >
                    <Typography sx={{ fontSize: '0.72rem', lineHeight: 1, fontWeight: isToday ? 700 : 400 }}>
                      {format(day, 'd')}
                    </Typography>
                    <Box sx={{ display: 'flex', gap: '2px', height: 4, mt: '2px' }}>
                      {items.slice(0, 3).map((item, idx) => (
                        <Box
                          key={idx}
                          sx={{
                            width: 4,
                            height: 4,
                            borderRadius: '50%',
                            bgcolor: inMonth ? itemColor(item) : 'rgba(255,255,255,0.2)',
                          }}
                        />
                      ))}
                    </Box>
                  </Box>
                </Box>
              )
            })}
          </Box>
        </>
      )}

      <CalendarDayDialog
        open={selectedDate !== null}
        date={selectedDate}
        items={selectedItems}
        onClose={() => setSelectedDate(null)}
      />
    </Box>
  )
}
