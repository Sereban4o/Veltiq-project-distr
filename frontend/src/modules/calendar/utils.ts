import type { EventInput } from '@fullcalendar/core'
import type { CalendarItem } from './types'

/** Accent color for a calendar item, falling back to a per-type default. */
export function itemColor(item: CalendarItem): string {
  if (item.type === 'task') return item.meta?.color || '#4C9AFF'
  if (item.type === 'deal') return item.meta?.color || '#36B37E'
  return item.meta?.color || '#FFAB00'
}

/** Convert an aggregated calendar item into a FullCalendar event object. */
export function toFullCalendarEvent(item: CalendarItem): EventInput {
  const startsAt = item.meta?.starts_at as string | undefined
  const endsAt = item.meta?.ends_at as string | undefined
  const allDay = item.meta?.all_day ?? !startsAt

  return {
    id: `${item.type}-${item.id}`,
    title: item.title,
    start: startsAt ?? item.date,
    end: endsAt ?? undefined,
    allDay: !!allDay,
    color: itemColor(item),
    extendedProps: { item },
  }
}

const RRULE_FREQ: Record<string, string> = {
  DAILY: 'Ежедневно',
  WEEKLY: 'Еженедельно',
  MONTHLY: 'Ежемесячно',
  YEARLY: 'Ежегодно',
}

const WEEKDAY_LABELS: Record<string, string> = {
  MO: 'пн',
  TU: 'вт',
  WE: 'ср',
  TH: 'чт',
  FR: 'пт',
  SA: 'сб',
  SU: 'вс',
}

/** Human-readable (Russian) description of an RFC 5545 RRULE value. */
export function describeRrule(rrule?: string | null): string {
  if (!rrule) return ''

  const parts = rrule.replace(/^RRULE:/i, '').split(';')
  const map: Record<string, string> = {}
  for (const part of parts) {
    const [key, value] = part.split('=')
    if (key) map[key.toUpperCase()] = value ?? ''
  }

  const freq = map.FREQ ?? ''
  let label = RRULE_FREQ[freq] ?? 'Повторяется'

  const interval = Number(map.INTERVAL ?? '1')
  if (interval > 1) label += ` (каждые ${interval})`

  if (map.BYDAY) {
    const days = map.BYDAY.split(',')
      .map((day) => WEEKDAY_LABELS[day.slice(-2)] ?? day)
      .join(', ')
    label += ` · ${days}`
  }

  if (map.COUNT) {
    label += ` · ${map.COUNT} раз`
  } else if (map.UNTIL) {
    label += ` · до ${map.UNTIL.slice(0, 8)}`
  }

  return label
}

/** Best-effort IANA timezone of the current browser. */
export function browserTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'
  } catch {
    return 'UTC'
  }
}

function pad(value: number): string {
  return value < 10 ? `0${value}` : String(value)
}

/** ISO string -> local `yyyy-MM-dd` for a native date input. */
export function isoToLocalDateInput(value?: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/** ISO string -> local `yyyy-MM-ddTHH:mm` for a native datetime-local input. */
export function isoToLocalInput(value?: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return `${isoToLocalDateInput(value)}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/** Local `yyyy-MM-ddTHH:mm` input value -> ISO 8601 string (null when invalid). */
export function localInputToIso(value?: string | null): string | null {
  if (!value) return null

  const match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(value)
  if (!match) return null

  const [, year, month, day, hours = '00', minutes = '00'] = match
  const date = new Date(Number(year), Number(month) - 1, Number(day), Number(hours), Number(minutes))
  if (Number.isNaN(date.getTime())) return null

  return date.toISOString()
}
