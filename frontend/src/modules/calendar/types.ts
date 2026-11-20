export type CalendarItemType = 'task' | 'deal' | 'event'

export interface CalendarItem {
  type: CalendarItemType
  id: number
  title: string
  /** Bare date string: yyyy-MM-dd */
  date: string
  meta: Record<string, any>
  link?: string | null
  editable?: boolean
  /** Stable key for recurring occurrences, e.g. "local:1:2024-01-01T09:00:00+00:00" */
  occurrence_key?: string
}

export interface CalendarRange {
  from: string
  to: string
  items: CalendarItem[]
  truncated?: boolean
}

/** RSVP answer of an attendee for an event invitation. */
export type RsvpStatus = 'accepted' | 'declined' | 'tentative' | 'needs_action'

/** Visibility of an event: private (only participants) or public (everyone). */
export type CalendarVisibility = 'private' | 'public'

export interface CalendarParticipantUser {
  id: number
  full_name?: string | null
  email?: string | null
}

export interface CalendarParticipant {
  id: number
  user_id?: number | null
  email?: string | null
  name?: string | null
  status: RsvpStatus
  role?: string | null
  user?: CalendarParticipantUser | null
}

export interface CalendarEventIssueLink {
  id: number
  key: string
  summary: string
}

export interface CalendarEventDealLink {
  id: number
  title: string
}

export interface CalendarLink {
  id: number
  issue_id?: number | null
  deal_id?: number | null
  issue?: CalendarEventIssueLink | null
  deal?: CalendarEventDealLink | null
}

export interface CalendarEvent {
  id: number
  title: string
  description?: string | null
  /** Bare date string: yyyy-MM-dd */
  event_date: string
  color?: string | null
  location?: string | null
  /** ISO 8601 timestamp */
  starts_at: string
  /** ISO 8601 timestamp */
  ends_at?: string | null
  all_day?: boolean
  timezone?: string | null
  visibility?: CalendarVisibility | null
  /** RFC 5545 RRULE (without the "RRULE:" prefix) */
  rrule?: string | null
  /** Excluded occurrence dates (ISO date or date-time strings) */
  exdates?: string[] | null
  /** Source calendar feed id, when the event comes from an external feed */
  feed_id?: number | null
  /** Event origin: "local", "feed" or "import" */
  source?: string | null
  is_organizer?: boolean
  my_status?: RsvpStatus | null
  participants?: CalendarParticipant[]
  links?: CalendarLink[]
}

export interface CalendarEventInput {
  title: string
  description?: string | null
  /** Bare date string: yyyy-MM-dd (legacy field) */
  event_date?: string
  color?: string | null
  location?: string | null
  /** ISO 8601 timestamp */
  starts_at?: string
  /** ISO 8601 timestamp */
  ends_at?: string | null
  all_day?: boolean
  timezone?: string | null
  visibility?: CalendarVisibility | null
  rrule?: string | null
  exdates?: string[] | null
  issue_ids?: number[]
  deal_ids?: number[]
}

export interface CalendarParticipantsInput {
  user_ids?: number[]
  emails?: string[]
  names?: string[]
}

export interface CalendarFeed {
  id: number
  name: string
  url: string
  color?: string | null
  sync_interval_minutes?: number
  is_active?: boolean
  last_synced_at?: string | null
  last_error?: string | null
  feed_events_count?: number
}

export interface CalendarFeedInput {
  name: string
  url: string
  color?: string | null
  sync_interval_minutes?: number
  is_active?: boolean
}

export interface IcsImportResult {
  imported: number
  updated: number
  skipped: number
}
