export interface CrmUser {
  id: number
  first_name?: string | null
  last_name?: string | null
}

export interface PipelineStage {
  id: number
  name: string
  sort_order: number
  color: string
  is_won: boolean
  is_lost: boolean
  deals_count?: number
}

export interface CrmContactInfo {
  Kind: string
  Presentation: string
  Value: string
  PhoneNumber: string
  Country: string
  Region: string
  City: string
}

export interface CrmCompany {
  id: number
  name: string
  website: string | null
  phone: string | null
  email: string | null
  address: string | null
  industry: string | null
  notes: string | null
  owner_id: number | null
  contacts_count?: number
  deals_count?: number
  owner?: CrmUser | null
  created_at: string
  // 1С counterparty fields
  ref_1c?: string | null
  full_name?: string | null
  inn?: string | null
  kpp?: string | null
  code_1c?: string | null
  legal_physical_person?: string | null
  registration_country?: string | null
  contact_info?: CrmContactInfo[] | null
  deleted_in_1c?: boolean
  synced_at?: string | null
}

export interface CrmContact {
  id: number
  first_name: string
  last_name: string | null
  email: string | null
  phone: string | null
  position: string | null
  company_id: number | null
  notes: string | null
  owner_id: number | null
  company?: { id: number; name: string } | null
  owner?: CrmUser | null
  created_at: string
}

/** Raw payload from the 1С CounterpartiesExchange service (PascalCase as sent). */
export interface OneCCounterparty {
  Ref: string
  Code: string
  Description: string
  FullDescription: string
  INN: string
  KPP: string
  LegalPhysicalPerson: string
  RegistrationCountry: string
  Partner: string
  HeadCounterpart: string
  RegistrationNumber: string
  TaxNumber: string
  OKPOCode: string
  InternationalDescription: string
  TranscriptionDescription: string
  AdditionalInformation: string
  DeletionMark: boolean
  ContactInfo: CrmContactInfo[]
}

export type DealStatus = 'open' | 'won' | 'lost'

export interface CrmDeal {
  id: number
  title: string
  company_id: number | null
  contact_id: number | null
  stage_id: number | null
  amount: string | number
  currency: string
  probability: number
  expected_close_date: string | null
  status: DealStatus
  description: string | null
  owner_id: number | null
  company?: { id: number; name: string } | null
  contact?: { id: number; first_name: string; last_name: string | null } | null
  stage?: PipelineStage | null
  owner?: CrmUser | null
  created_at: string
  updated_at: string
}

export type ActivityType = 'call' | 'meeting' | 'task' | 'email' | 'note'

export type CallDirection = 'inbound' | 'outbound'
export type CallOutcome = 'connected' | 'no_answer' | 'busy' | 'callback'

export interface CrmActivity {
  id: number
  type: ActivityType
  subject: string
  description: string | null
  due_at: string | null
  is_completed: boolean
  deal_id: number | null
  contact_id: number | null
  company_id: number | null
  owner_id: number | null
  direction?: CallDirection | null
  outcome?: CallOutcome | null
  duration_seconds?: number | null
  phone?: string | null
  deal?: { id: number; title: string } | null
  contact?: { id: number; first_name: string; last_name: string | null } | null
  company?: { id: number; name: string } | null
  owner?: CrmUser | null
  created_at: string
}

export interface CrmDashboard {
  companies: number
  contacts: number
  open_deals: number
  pipeline_amount: number
  won_amount: number
  won_this_month: number
  open_tasks: number
  overdue_tasks: number
  by_stage: { id: number; name: string; color: string; deals_count: number; amount: number }[]
  recent_activities: CrmActivity[]
}

export type CrmAutomationTrigger = 'deal_created' | 'stage_changed' | 'deal_won'
export type CrmAutomationAction = 'create_task' | 'send_notification'

export interface CrmAutomationRule {
  id: number
  name: string
  trigger: CrmAutomationTrigger
  conditions: Record<string, unknown> | null
  action: CrmAutomationAction
  action_payload: Record<string, unknown> | null
  is_active: boolean
  position: number
  created_by?: number | null
  created_at?: string
  updated_at?: string
}

export interface CrmFile {
  id: number
  original_name: string
  stored_name?: string
  mime_type?: string | null
  size: number
  uploaded_by?: number | null
  created_at?: string
  updated_at?: string
  uploader?: { id: number; first_name?: string | null; last_name?: string | null } | null
}

export interface CrmEmail {
  id: number
  subject: string | null
  from_name?: string | null
  from_email?: string | null
  preview?: string | null
  received_at: string | null
  sent_at: string | null
  folder?: string | null
  is_read?: boolean
  has_attachments?: boolean
}

export interface CrmImportError {
  row: number
  message: string
}

export interface CrmImportResult {
  total: number
  valid: number
  created: number
  updated: number
  skipped: number
  errors: CrmImportError[]
  duplicates: CrmImportError[]
  dry_run: boolean
  headers: string[]
  preview: Record<string, unknown>[]
}

export interface CrmReportSummary {
  won_count: number
  lost_count: number
  win_rate: number
  avg_deal_size: number
  avg_cycle_days: number
  open_count: number
  open_amount: number
}

export interface CrmReportFunnelStage {
  id: number
  name: string
  color: string
  count: number
  amount: number
  weighted_amount: number
}

export interface CrmReportConversionPair {
  from: { id: number; name: string }
  to: { id: number; name: string }
  entered: number
  converted: number
  rate: number
}

export interface CrmReportConversion {
  pairs: CrmReportConversionPair[]
  won_rate: number
  lost_rate: number
}

export interface CrmReportRevenueBucket {
  key: string
  label: string
  won_count: number
  won_amount: number
}

export interface CrmReportRevenue {
  period: 'month' | 'quarter' | 'year'
  from: string
  to: string
  buckets: CrmReportRevenueBucket[]
}
