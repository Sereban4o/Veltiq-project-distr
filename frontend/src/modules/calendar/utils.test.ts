import { describe, expect, it } from 'vitest'
import type { CalendarItem } from './types'
import { describeRrule, isoToLocalDateInput, itemColor, localInputToIso, toFullCalendarEvent } from './utils'

function item(overrides: Partial<CalendarItem> = {}): CalendarItem {
  return {
    type: 'event',
    id: 1,
    title: 'Meeting',
    date: '2026-01-15',
    meta: {},
    ...overrides,
  }
}

describe('itemColor', () => {
  it('uses per-type defaults', () => {
    expect(itemColor(item({ type: 'task' }))).toBe('#4C9AFF')
    expect(itemColor(item({ type: 'deal' }))).toBe('#36B37E')
    expect(itemColor(item({ type: 'event' }))).toBe('#FFAB00')
  })

  it('prefers an explicit colour from meta', () => {
    expect(itemColor(item({ type: 'task', meta: { color: '#123456' } }))).toBe('#123456')
  })
})

describe('toFullCalendarEvent', () => {
  it('maps id, title, dates and colour', () => {
    const event = toFullCalendarEvent(
      item({
        type: 'task',
        id: 7,
        title: 'Ship it',
        date: '2026-02-01',
        meta: { starts_at: '2026-02-01T10:00:00Z', ends_at: '2026-02-01T11:00:00Z', all_day: false },
      }),
    )

    expect(event.id).toBe('task-7')
    expect(event.title).toBe('Ship it')
    expect(event.start).toBe('2026-02-01T10:00:00Z')
    expect(event.end).toBe('2026-02-01T11:00:00Z')
    expect(event.allDay).toBe(false)
    expect(event.color).toBe('#4C9AFF')
  })

  it('treats an item without starts_at as all-day', () => {
    const event = toFullCalendarEvent(item({ date: '2026-03-03' }))

    expect(event.start).toBe('2026-03-03')
    expect(event.end).toBeUndefined()
    expect(event.allDay).toBe(true)
  })
})

describe('describeRrule', () => {
  it('returns an empty string for an empty rule', () => {
    expect(describeRrule(null)).toBe('')
    expect(describeRrule('')).toBe('')
  })

  it('describes frequency and interval', () => {
    expect(describeRrule('FREQ=WEEKLY')).toBe('Еженедельно')
    expect(describeRrule('RRULE:FREQ=DAILY;INTERVAL=3')).toBe('Ежедневно (каждые 3)')
  })

  it('describes weekdays, count and until', () => {
    expect(describeRrule('FREQ=WEEKLY;BYDAY=MO,WE')).toBe('Еженедельно · пн, ср')
    expect(describeRrule('FREQ=DAILY;COUNT=5')).toBe('Ежедневно · 5 раз')
    expect(describeRrule('FREQ=DAILY;UNTIL=20261231T000000Z')).toBe('Ежедневно · до 20261231')
  })
})

describe('local date input helpers', () => {
  it('formats an ISO date for a native date input', () => {
    expect(isoToLocalDateInput('2026-05-06T12:34:56Z')).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })

  it('returns an empty string for missing or invalid input', () => {
    expect(isoToLocalDateInput(null)).toBe('')
    expect(isoToLocalDateInput('not-a-date')).toBe('')
  })

  it('converts a local datetime input back to ISO', () => {
    const iso = localInputToIso('2026-05-06T09:30')

    expect(iso).toBeTruthy()
    expect(new Date(iso as string).getTime()).not.toBeNaN()
  })

  it('returns null for invalid input', () => {
    expect(localInputToIso(null)).toBeNull()
    expect(localInputToIso('nonsense')).toBeNull()
  })
})
