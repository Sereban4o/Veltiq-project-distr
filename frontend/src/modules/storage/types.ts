export interface StorageEntry {
  name: string
  path: string
  type: 'dir' | 'file'
  size: number | null
  created_at: string
  modified_at: string
  extension: string | null
  kind: string
}

export type StorageEditorMode =
  'text' | 'richtext' | 'grid' | 'pdf' | 'image' | 'video' | 'archive' | 'office' | 'binary'

export interface StorageListing {
  configured: boolean
  protocol?: string | null
  address?: string | null
  path: string
  root: string | null
  writable: boolean
  entries: StorageEntry[]
  message?: string
}

export interface StorageFile {
  name: string
  path: string
  size: number
  created_at: string
  modified_at: string
  extension: string
  kind: string
  mime: string
  is_text: boolean
  is_image: boolean
  editable: boolean
  too_large: boolean
  editor: StorageEditorMode
  content: string | null
}

/** Preview generation state of an entry (`storage_meta.preview_status`). */
export type PreviewStatus = 'none' | 'pending' | 'ready' | 'failed'

/** One file/dir inside an archive listing. */
export interface ArchiveListingEntry {
  name: string
  size: number | null
  is_dir: boolean
}

/** Read-only listing of an archive (.zip/.7z/.rar/...) entry. */
export interface ArchiveListing {
  entries: ArchiveListingEntry[]
  truncated: boolean
}

/** Quota summary for the current user (`StorageQuotaService::summary`). */
export interface StorageQuota {
  used: number
  used_live: number
  used_trash: number
  used_versions: number
  limit: number | null
  percent: number | null
  source: string
}

/** A stored snapshot of a file. */
export interface StorageVersion {
  id: number
  version: number
  size: number
  mime: string | null
  source: string
  status: string
  author_id: number | null
  created_at: string
}

/** A trashed (recoverable) entry. */
export interface StorageTrashItem {
  id: number
  path: string
  name: string
  type: 'dir' | 'file'
  size: number
  items_count: number
  owner_id: number | null
  deleted_by: number | null
  deleted_at: string | null
  expires_at: string | null
}

/** A public share link for an entry. */
export interface StorageShare {
  id: number
  token: string | null
  path: string | null
  url: string | null
  file: string
  requires_password: boolean
  can_download: boolean
  max_downloads: number | null
  downloads_count: number
  expires_at: string | null
  created_at: string | null
}

/** A single storage access-journal record. */
export interface StorageAuditEntry {
  id: number
  user_id: number | null
  action: string
  path: string | null
  size: number | null
  status: number
  ip: string | null
  user_agent: string | null
  payload: Record<string, unknown> | null
  created_at: string | null
}

/** One user row in the admin quota table. */
export interface AdminQuotaRow {
  id: number
  email: string
  name: string
  quota_bytes: number | null
  used: number
  used_trash: number
  used_versions: number
  limit: number | null
  percent: number | null
}

/** Admin-tunable storage settings. */
export interface StorageSettings {
  default_quota_bytes: number
  version_max_per_file: number
  version_retention_days: number
  trash_retention_days: number
  audit_retention_days: number
  previews_enabled: boolean
  office_autosave_enabled: boolean
}

/** Resumable (chunked) upload session state. */
export interface UploadSession {
  upload_id: string
  chunk_size: number
  total_chunks: number
  received: number[]
}

/** Anonymous metadata for a public share link. */
export interface PublicShareInfo {
  name: string
  type: 'dir' | 'file'
  size: number
  mime: string | null
  previewable: boolean
  requires_password: boolean
  has_access: boolean
  can_download: boolean
  expires_at: string | null
  downloads_left: number | null
}
