import apiClient from '@/modules/shared/api/apiClient'
import { unwrapData, unwrapList } from '@/modules/shared/utils/apiResponse'
import type {
  CalendarEvent,
  CalendarEventInput,
  CalendarFeed,
  CalendarFeedInput,
  CalendarParticipantsInput,
  CalendarRange,
  IcsImportResult,
  RsvpStatus,
} from './types'

const base = '/v1/calendar'

interface EventListParams {
  from: string
  to: string
  issue_id?: number
  deal_id?: number
  feed_id?: number
  q?: string
}

export interface FreeSlot {
  start: string
  end: string
}

export interface FreeSlotsResponse {
  date: string
  duration: number
  timezone: string
  slots: FreeSlot[]
}

/** Save a downloaded blob to disk using an anchor element. */
function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}

export const calendarApi = {
  range: async (from: string, to: string): Promise<CalendarRange> =>
    unwrapData<CalendarRange>(await apiClient.get(`${base}`, { params: { from, to } })) as CalendarRange,

  events: async (params: EventListParams): Promise<CalendarRange> =>
    unwrapData<CalendarRange>(await apiClient.get(`${base}/events`, { params })) as CalendarRange,

  freeSlots: async (params: { date: string; duration?: number; user_ids?: number[] }): Promise<FreeSlotsResponse> =>
    unwrapData<FreeSlotsResponse>(await apiClient.get(`${base}/free-slots`, { params })) as FreeSlotsResponse,

  event: async (id: number): Promise<CalendarEvent> =>
    unwrapData<CalendarEvent>(await apiClient.get(`${base}/events/${id}`)) as CalendarEvent,

  createEvent: async (payload: CalendarEventInput): Promise<CalendarEvent> =>
    unwrapData<CalendarEvent>(await apiClient.post(`${base}/events`, payload)) as CalendarEvent,

  updateEvent: async (id: number, payload: Partial<CalendarEventInput>): Promise<CalendarEvent> =>
    unwrapData<CalendarEvent>(await apiClient.patch(`${base}/events/${id}`, payload)) as CalendarEvent,

  deleteEvent: (id: number, occurrenceStart?: string) =>
    apiClient.delete(`${base}/events/${id}`, {
      params: occurrenceStart ? { occurrence_start: occurrenceStart } : undefined,
    }),

  addParticipants: async (id: number, payload: CalendarParticipantsInput): Promise<CalendarEvent> =>
    unwrapData<CalendarEvent>(await apiClient.post(`${base}/events/${id}/participants`, payload)) as CalendarEvent,

  removeParticipant: async (id: number, attendeeId: number): Promise<CalendarEvent> =>
    unwrapData<CalendarEvent>(
      await apiClient.delete(`${base}/events/${id}/participants/${attendeeId}`),
    ) as CalendarEvent,

  rsvp: async (id: number, status: RsvpStatus): Promise<CalendarEvent> =>
    unwrapData<CalendarEvent>(await apiClient.post(`${base}/events/${id}/rsvp`, { status })) as CalendarEvent,

  downloadEventIcs: async (id: number): Promise<Blob> => {
    const response = await apiClient.get(`${base}/events/${id}/ics`, { responseType: 'blob' })
    const blob = response.data as Blob
    saveBlob(blob, `event-${id}.ics`)
    return blob
  },

  downloadRangeIcs: async (from: string, to: string): Promise<Blob> => {
    const response = await apiClient.get(`${base}/ics/export`, {
      params: { from, to },
      responseType: 'blob',
    })
    const blob = response.data as Blob
    saveBlob(blob, 'calendar.ics')
    return blob
  },

  feeds: async (): Promise<CalendarFeed[]> => unwrapList<CalendarFeed>(await apiClient.get(`${base}/feeds`)),

  createFeed: async (payload: CalendarFeedInput): Promise<CalendarFeed> =>
    unwrapData<CalendarFeed>(await apiClient.post(`${base}/feeds`, payload)) as CalendarFeed,

  updateFeed: async (id: number, payload: Partial<CalendarFeedInput>): Promise<CalendarFeed> =>
    unwrapData<CalendarFeed>(await apiClient.patch(`${base}/feeds/${id}`, payload)) as CalendarFeed,

  deleteFeed: (id: number) => apiClient.delete(`${base}/feeds/${id}`),

  syncFeed: (id: number) => apiClient.post(`${base}/feeds/${id}/sync`),

  importIcs: async (file: File): Promise<IcsImportResult> => {
    const formData = new FormData()
    formData.append('file', file)
    return unwrapData<IcsImportResult>(await apiClient.post(`${base}/ics/import`, formData)) as IcsImportResult
  },
}
