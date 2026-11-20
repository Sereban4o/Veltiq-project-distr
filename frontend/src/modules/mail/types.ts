export type MailFolder = 'inbox' | 'sent' | 'drafts' | 'trash'
export type MailProtocol = 'imap' | 'pop'
export type MailEncryption = 'ssl' | 'tls' | 'none'

export interface MailSettings {
  email: string
  display_name: string | null
  incoming_protocol: MailProtocol
  imap_host: string | null
  imap_port: number | null
  imap_encryption: MailEncryption | null
  imap_username: string | null
  imap_password_set: boolean
  pop_host: string | null
  pop_port: number | null
  pop_encryption: MailEncryption | null
  pop_username: string | null
  pop_password_set: boolean
  smtp_host: string | null
  smtp_port: number | null
  smtp_encryption: MailEncryption | null
  smtp_username: string | null
  smtp_password_set: boolean
  is_active: boolean
  last_synced_at: string | null
  last_sync_error: string | null
}

export interface MailRecipient {
  name?: string | null
  email?: string | null
}

export interface MailAttachment {
  id: number
  filename: string
  mime_type: string | null
  size: number
}

export interface MailMessage {
  id: number
  folder: MailFolder
  mail_folder_id?: number | null
  from_email: string | null
  from_name: string | null
  message_id?: string | null
  in_reply_to?: string | null
  to_recipients: MailRecipient[] | null
  cc_recipients: MailRecipient[] | null
  bcc_recipients: MailRecipient[] | null
  subject: string | null
  preview: string | null
  body_text: string | null
  body_html: string | null
  is_read: boolean
  is_starred: boolean
  is_done?: boolean
  assigned_to?: number | null
  snoozed_until?: string | null
  has_attachments: boolean
  source: string
  sent_at: string | null
  received_at: string | null
  created_at: string
  updated_at: string
  attachments?: MailAttachment[]
}

export interface ComposePayload {
  to: string[]
  cc?: string[]
  bcc?: string[]
  subject?: string
  body_text?: string
  body_html?: string
  draft_id?: number | null
  in_reply_to?: string | null
  account_id?: number | null
}

/** A mailbox the user may access: their own or a shared (team) inbox. */
export interface MailAccountSummary {
  id: number
  email: string
  display_name: string | null
  is_owner: boolean
  can_send: boolean
  owner_name: string | null
  members_count: number
}

/** A member granted access to a shared mailbox. */
export interface MailAccountMember {
  id: number
  name: string | null
  email: string | null
  can_send: boolean
}

export interface MailFolderRecord {
  id: number
  name: string
  position: number
  messages_count?: number
}

export type MailRuleField = 'from' | 'to' | 'subject' | 'body' | 'has_attachment'
export type MailRuleOp = 'contains' | 'equals' | 'regex' | 'is_true'

export interface MailRuleCondition {
  field: MailRuleField
  op: MailRuleOp
  value?: string
}

export interface MailRuleGroup {
  match?: 'all' | 'any'
  conditions: MailRuleCondition[]
}

export interface MailRuleConditions {
  match: 'all' | 'any'
  groups: MailRuleGroup[]
}

export interface MailFilterRule {
  id: number
  name: string
  is_active: boolean
  position: number
  action_target_folder_id: number
  conditions: MailRuleConditions
  target_folder?: { id: number; name: string } | null
}

export interface ComposeInitial {
  mode?: 'new' | 'reply' | 'reply_all' | 'forward'
  to?: string[]
  cc?: string[]
  bcc?: string[]
  subject?: string
  body?: string
  /** Same content as `body`, as HTML for the visual editor. */
  bodyHtml?: string
  in_reply_to?: string | null
}

export interface MailContact {
  id: number
  email: string
  name: string | null
  created_by?: number | null
  created_at?: string
  updated_at?: string
}

export interface MailContactsImportResult {
  added: number
  updated: number
  skipped: number
  total: number
}

export interface EmptyTrashResult {
  deleted: number
  failed: number
  remaining: number
  message: string
}

/** A recipient chip: a contact picked from the address book or a raw address string. */
export type AddressValue = MailContact | string

/** A file queued for upload when composing a message. */
export interface OutgoingAttachment {
  /** Server-side id (present for attachments already stored on a draft). */
  id?: number
  /** Raw browser file; null for attachments that only exist on the server. */
  file: File | null
  filename: string
  size: number
  mime_type: string | null
}

/** Sender-based folder created from a message author. */
export interface FolderFromSenderResult {
  folder: MailFolderRecord
  moved: number
  rule_created: boolean
}

/** Bulk operations available from the message list toolbar. */
export type BulkAction = 'read' | 'unread' | 'star' | 'unstar' | 'move' | 'delete'

export interface BulkResult {
  updated: number
  deleted?: number
  failed?: number[]
}

/** A recipient autocomplete suggestion derived from history / the address book. */
export interface MailSuggestion {
  email: string
  name?: string | null
  source?: 'history' | 'contact'
  count?: number
}

export interface MailSignature {
  id: number
  name: string
  body_html: string
  is_default: boolean
  position?: number
}

export interface MailTemplate {
  id: number
  name: string
  subject: string | null
  body_html: string
  position?: number
}

/** One conversation in the grouped (thread) message list. */
export interface MailThreadSummary {
  thread_key: string
  latest: MailMessage
  count: number
  unread_count: number
  has_attachments: boolean
  last_at: string | null
}

/** Full conversation returned by the thread detail endpoint. */
export interface MailThreadDetail {
  thread_key: string
  messages: MailMessage[]
  count?: number
  unread_count?: number
}
