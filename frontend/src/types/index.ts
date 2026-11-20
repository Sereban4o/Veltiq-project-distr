// ─── User ───
export interface User {
  id: number
  email: string
  first_name: string
  last_name: string
  avatar?: string
  status: 'active' | 'suspended' | 'pending'
  email_verified_at?: string
  created_at: string
  updated_at: string
  full_name?: string
  initials?: string
  role_id?: number
  role?: Role
}

// ─── Role ───
export interface Role {
  id: number
  name: string
  description?: string
  created_at: string
  updated_at: string
}

// ─── Project ───
export interface Project {
  id: number
  key: string
  name: string
  description?: string
  avatar?: string
  type: 'scrum' | 'kanban' | 'basic'
  visibility: 'private' | 'public' | 'team'
  properties?: Record<string, any>
  created_by: number
  created_at: string
  updated_at: string
  members?: ProjectMember[]
  creator?: User
}

export interface ProjectMember {
  id: number
  project_id: number
  user_id: number
  role: 'admin' | 'member' | 'viewer'
  user?: User
}

// ─── Issue ───
export interface Issue {
  id: number
  project_id: number
  issue_type_id: number
  status_id: number
  created_by: number
  assigned_to?: number
  reporter_id?: number
  parent_id?: number
  epic_id?: number
  summary: string
  description?: string
  key: string
  sequence: number
  custom_fields?: Record<string, any>
  priority: 'lowest' | 'low' | 'medium' | 'high' | 'highest'
  start_date?: string | null
  due_date?: string | null
  estimated_start?: string
  estimated_end?: string
  story_points?: number
  original_estimate_hours?: number
  time_spent_hours: number
  remaining_hours?: number
  created_at: string
  updated_at: string
  deleted_at?: string

  // Relations
  project?: Project
  type?: IssueType
  status?: IssueStatus
  assignee?: User
  reporter?: User
  creator?: User
  parent?: Issue
  subtasks?: Issue[]
  epic?: Issue
  epic_issues?: Issue[]
  comments?: IssueComment[]
  attachments?: IssueAttachment[]
  links?: IssueLink[]
}

export interface IssueType {
  id: number
  name: string
  icon?: string
  color?: string
  is_epic: boolean
  is_subtask: boolean
}

export interface IssueStatus {
  id: number
  name: string
  category: 'todo' | 'in_progress' | 'done' | 'undone'
  color?: string
}

export interface IssueComment {
  id: number
  issue_id: number
  user_id: number
  parent_comment_id?: number
  body: string
  rendered_body?: string
  time_spent_hours?: number
  created_at: string
  updated_at: string
  user?: User
  replies?: IssueComment[]
  attachments?: {
    id: number
    file_id: number
    file?: { id: number; original_name: string; mime_type?: string | null; size?: number } | null
  }[]
  mentioned_users?: { id: number; full_name: string; email: string }[]
}

export interface IssueActivity {
  id: number
  action: string
  description?: string | null
  changes: { field: string; old: unknown; new: unknown }[]
  properties?: Record<string, unknown> | null
  created_at: string
  user?: { id: number; full_name: string; initials?: string; avatar?: string | null } | null
}

export interface IssueChecklistItem {
  id: number
  checklist_id: number
  body: string
  done: boolean
  assignee_id?: number | null
  position: number
  assignee?: { id: number; full_name: string; initials?: string } | null
}

export interface IssueChecklist {
  id: number
  issue_id: number
  title: string
  position: number
  items: IssueChecklistItem[]
  total: number
  done_count: number
}

export interface IssueLinkRef {
  id: number
  link_type: string
  type: string
  direction: 'outgoing' | 'incoming'
  created_by: number
  created_at: string
  issue: {
    id: number
    key: string
    summary: string
    status?: { id: number; name: string; category?: string; color?: string | null } | null
    type?: { id: number; name: string; color?: string | null } | null
  } | null
}

export interface IssueSubtask {
  id: number
  key: string
  summary: string
  status_id?: number | null
  status?: IssueStatus | null
  assignee?: User | null
}

export interface IssueWorklog {
  id: number
  issue_id: number
  user_id: number
  seconds: number
  hours: number
  comment?: string | null
  started_at?: string | null
  ended_at?: string | null
  is_running: boolean
  user?: { id: number; full_name: string; initials?: string; avatar?: string | null } | null
}

export interface IssueWorklogsResponse {
  data: IssueWorklog[]
  total_seconds: number
  running: IssueWorklog | null
}

export interface IssueAttachment {
  id: number
  issue_id: number
  file_id: number
  uploaded_by: number
  created_at: string
  file?: File
}

export interface IssueLink {
  id: number
  source_issue_id: number
  target_issue_id: number
  link_type: string
  created_by: number
  created_at: string
  source?: Issue
  target?: Issue
  creator?: User
}

// ─── Board ───
export interface Board {
  id: number
  project_id: number
  name: string
  type: 'kanban' | 'scrum'
  created_by: number
  filter_query?: Record<string, any>
  swimlanes?: Record<string, any>
  swimlane_by?: 'none' | 'assignee' | 'priority' | 'epic'
  wip_enforcement?: boolean
  order: number
  created_at: string
  updated_at: string
  columns?: BoardColumn[]
  project?: Project
  creator?: User
}

export interface BoardColumn {
  id: number
  board_id: number
  name: string
  status_id?: number
  order: number
  wip_limit?: number
  created_at: string
  updated_at: string
}

export interface BoardView {
  board: {
    id: number
    project_id: number
    name: string
    type: 'kanban' | 'scrum'
    swimlane_by: 'none' | 'assignee' | 'priority' | 'epic'
    wip_enforcement: boolean
  }
  columns: (BoardColumn & { status?: IssueStatus | null })[]
  issues: {
    id: number
    key: string
    summary: string
    project_id: number
    status_id?: number | null
    assigned_to?: number | null
    priority?: string
    epic_id?: number | null
    sequence?: number
    swimlane_key?: string
    swimlane_label?: string
    assignee?: { id: number; full_name: string; initials?: string } | null
    type?: { id: number; name: string; color?: string | null } | null
    status?: IssueStatus | null
  }[]
  swimlanes: { key: string; label: string; count: number }[]
}

export interface SavedFilter {
  id: number
  user_id: number
  project_id?: number | null
  name: string
  jql_query?: string | null
  filters?: Record<string, unknown> | null
  is_global: boolean
  created_at: string
  updated_at: string
}

// ─── Sprint ───
export interface Sprint {
  id: number
  project_id: number
  board_id: number
  name: string
  start_date?: string
  end_date?: string
  status: 'planning' | 'active' | 'completed' | 'cancelled'
  sequence: number
  created_at: string
  updated_at: string
  issues?: Issue[]
}

// ─── Space ───
export interface Space {
  id: number
  created_by: number
  key: string
  name: string
  description?: string
  type: 'project' | 'personal' | 'knowledge'
  visibility: 'private' | 'public' | 'team'
  avatar?: string
  properties?: Record<string, any>
  created_at: string
  updated_at: string
  can_edit?: boolean
  can_admin?: boolean
  members?: SpaceMember[]
  creator?: User
}

export interface SpaceMember {
  id: number
  space_id: number
  user_id: number
  role: 'admin' | 'editor' | 'viewer'
  user?: User
}

// ─── Page ───
export interface Page {
  id: number
  space_id: number
  parent_id?: number | null
  position?: number
  author_id: number
  last_edited_by?: number
  title: string
  body?: string
  status: 'draft' | 'published' | 'archived'
  version: number
  labels: string[]
  metadata?: Record<string, any>
  created_at: string
  updated_at: string
  deleted_at?: string

  // Computed by the API for the current user
  can_edit?: boolean
  can_manage_permissions?: boolean
  can_comment?: boolean
  ancestors?: PageAncestor[]

  // Relations
  space?: Space
  parent?: Page
  children?: Page[]
  author?: User
  lastEditor?: User
  versions?: PageVersion[]
  comments?: PageComment[]
  labels_list?: PageLabel[]
  attachments?: PageAttachment[]
}

export interface PageAncestor {
  id: number
  title: string
}

// ─── Page tree ───
export interface PageNode {
  id: number
  parent_id: number | null
  position: number
  title: string
  status: 'draft' | 'published' | 'archived'
  labels: string[]
  icon?: string | null
  has_children: boolean
  can_edit: boolean
  updated_at: string
  author?: User
}

// ─── Page templates ───
export interface PageTemplate {
  id: number
  space_id: number | null
  created_by: number | null
  name: string
  description?: string | null
  body?: string | null
  labels: string[]
  created_at: string
  updated_at: string
  creator?: User | null
}

// ─── Page permissions ───
export interface PagePermissionEntry {
  id: number
  page_id: number
  user_id: number
  permission: 'viewer' | 'editor'
  user?: User
}

// ─── Page diff ───
export interface PageDiffLine {
  type: 'context' | 'added' | 'removed'
  text: string
}

export interface PageDiffResponse {
  version_a: PageVersion
  version_b: PageVersion
  diff: {
    lines: PageDiffLine[]
    stats: { added: number; removed: number }
    unified: string
  }
}

// ─── Knowledge base search ───
export interface KbSearchResult {
  id: number
  title: string
  space_id: number
  space_key: string
  space_name: string
  excerpt: string
  labels: string[]
  updated_at: string
}

export interface PageVersion {
  id: number
  page_id: number
  version: number
  author_id: number
  summary?: string
  body_snapshot: string
  created_at: string
  author?: User
}

export interface PageComment {
  id: number
  page_id: number
  user_id: number
  body: string
  line_number?: number
  column_number?: number
  resolved: boolean
  created_at: string
  updated_at: string
  user?: User
}

export interface PageLabel {
  id: number
  page_id: number
  label: string
}

export interface PageAttachment {
  id: number
  page_id: number
  file_id: number
  uploaded_by: number
  created_at: string
  updated_at: string
  file?: File
  uploader?: User
}

// ─── File ───
export interface File {
  id: number
  uploaded_by: number
  original_name: string
  stored_name: string
  mime_type: string
  size: number
  disk: string
  path: string
  preview_path?: string
  metadata?: Record<string, any>
  created_at: string
  updated_at: string
}

// ─── Notification ───
export interface Notification {
  id: number
  user_id: number
  actor_id?: number
  type: string
  subject_type: string
  subject_id: number
  data?: Record<string, any>
  read: boolean
  read_at?: string
  created_at: string
  updated_at: string
  actor?: User
  subject?: Issue | Page
}

// ─── Notification pagination & preferences ───
export interface NotificationPaginator {
  data: Notification[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from?: number
  to?: number
}

export type DigestFrequency = 'off' | 'daily' | 'weekly'

/** Per-channel delivery preference for a single notification type. */
export interface NotificationPreferenceEntry {
  in_app: boolean
  email: boolean
  websocket: boolean
  configurable: boolean
  label: string
  group: string
}

/** A UI group of notification types (e.g. "Задачи"). */
export interface NotificationTypeGroup {
  label: string
  types: Record<string, string>
}

export interface NotificationPreferencesResponse {
  types: Record<string, NotificationTypeGroup>
  channels: { value: string; label: string }[]
  preferences: Record<string, NotificationPreferenceEntry>
  digest_frequency: DigestFrequency
  digest_frequencies: { value: DigestFrequency; label: string }[]
}

// ─── Activity Log ───
export interface ActivityLog {
  id: number
  user_id: number
  subject_type: string
  subject_id: number
  action: string
  changes?: Record<string, any>
  properties?: Record<string, any>
  created_at: string
  updated_at: string
}

// ─── API Response ───
export interface PaginatedResponse<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number
  to: number
  links?: {
    url: string | null
    label: string
    active: boolean
  }[]
}

// ─── Auth ───
export interface AuthResponse {
  user: User
  token: string
  token_type: string
}

// ─── Issue Filter ───
export interface IssueFilter {
  project_id?: number
  status_id?: number
  type_id?: number
  assignee_id?: number
  priority?: string
  search?: string
  epic_id?: number
  parent_id?: number
  sort_by?: string
  sort_dir?: 'asc' | 'desc'
  per_page?: number
}
