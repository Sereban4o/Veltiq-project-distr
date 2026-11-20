import apiClient from '@/modules/shared/api/apiClient'
import { unwrapData } from '@/modules/shared/utils/apiResponse'
import type {
  AdminQuotaRow,
  ArchiveListing,
  PreviewStatus,
  PublicShareInfo,
  StorageAuditEntry,
  StorageFile,
  StorageListing,
  StorageQuota,
  StorageSettings,
  StorageShare,
  StorageTrashItem,
  StorageVersion,
  UploadSession,
} from '../types'

/** Centralised React Query keys for the storage module. */
export const storageKeys = {
  list: (path: string) => ['storage', 'list', path] as const,
  file: (path: string) => ['storage', 'file', path] as const,
  quota: () => ['storage', 'quota'] as const,
  versions: (path: string) => ['storage', 'versions', path] as const,
  trash: (filters: Record<string, unknown>) => ['storage', 'trash', filters] as const,
  shares: (path: string) => ['storage', 'shares', path] as const,
  audit: (filters: Record<string, unknown>) => ['storage', 'audit', filters] as const,
  archive: (path: string) => ['storage', 'archive', path] as const,
  adminQuotas: (search: string) => ['admin', 'storage', 'quotas', search] as const,
  adminAudit: (filters: Record<string, unknown>) => ['admin', 'storage', 'audit', filters] as const,
  adminSettings: () => ['admin', 'storage', 'settings'] as const,
}

// ─── Browsing ───

export async function fetchListing(path: string): Promise<StorageListing> {
  return unwrapData<StorageListing>(await apiClient.get('/v1/storage/files', { params: { path } })) as StorageListing
}

export async function fetchFile(path: string): Promise<StorageFile | null> {
  return unwrapData<StorageFile>(await apiClient.get('/v1/storage/file', { params: { path } }))
}

export async function createEntry(path: string, type: 'file' | 'dir', content?: string): Promise<void> {
  await apiClient.post('/v1/storage/entry', { path, type, content: type === 'file' ? (content ?? '') : undefined })
}

export async function deleteEntry(path: string, permanent = false): Promise<{ trashed: boolean; trash_id?: number }> {
  const data = unwrapData<{ trashed: boolean; trash_id?: number }>(
    await apiClient.delete('/v1/storage/entry', { data: { path, permanent } }),
  )
  return data ?? { trashed: false }
}

export async function moveEntry(from: string, to: string, overwrite = false): Promise<void> {
  await apiClient.post('/v1/storage/entry/move', { from, to, overwrite })
}

export async function saveFile(path: string, content: string): Promise<StorageFile | null> {
  return unwrapData<StorageFile>(await apiClient.put('/v1/storage/file', { path, content }))
}

export function previewUrl(path: string, size: 'thumb' | 'medium' = 'thumb'): string {
  return `/api/v1/storage/preview?path=${encodeURIComponent(path)}&size=${size}`
}

export async function fetchArchive(path: string): Promise<ArchiveListing> {
  return unwrapData<ArchiveListing>(await apiClient.get('/v1/storage/archive', { params: { path } })) as ArchiveListing
}

export async function downloadBlob(path: string, endpoint = '/v1/storage/download'): Promise<Blob> {
  const res = await apiClient.get(endpoint, { params: { path }, responseType: 'blob', timeout: 0 })
  return res.data as Blob
}

export async function triggerDownload(path: string, name: string, endpoint = '/v1/storage/download'): Promise<void> {
  const blob = await downloadBlob(path, endpoint)
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = name
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

// ─── Quota ───

export async function fetchQuota(): Promise<StorageQuota> {
  return unwrapData<StorageQuota>(await apiClient.get('/v1/storage/quota')) as StorageQuota
}

// ─── Versions ───

export async function fetchVersions(path: string): Promise<StorageVersion[]> {
  const data = unwrapData<StorageVersion[]>(await apiClient.get('/v1/storage/versions', { params: { path } }))
  return Array.isArray(data) ? data : []
}

export async function restoreVersion(path: string, version: number): Promise<void> {
  await apiClient.post(`/v1/storage/versions/${version}/restore`, { path })
}

export async function deleteVersion(path: string, version: number): Promise<void> {
  await apiClient.delete(`/v1/storage/versions/${version}`, { params: { path } })
}

export async function downloadVersion(path: string, version: number, name: string): Promise<void> {
  const res = await apiClient.get(`/v1/storage/versions/${version}/download`, {
    params: { path },
    responseType: 'blob',
    timeout: 0,
  })
  const url = URL.createObjectURL(res.data as Blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${name}.v${version}`
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

// ─── Trash ───

export async function fetchTrash(
  filters: Record<string, unknown> = {},
): Promise<{ data: StorageTrashItem[]; meta: any }> {
  const res = await apiClient.get('/v1/storage/trash', { params: filters })
  const payload = res.data ?? {}
  return { data: Array.isArray(payload.data) ? payload.data : [], meta: payload.meta ?? {} }
}

export async function restoreTrashItem(
  id: number,
  strategy: 'rename' | 'overwrite' | 'fail' = 'rename',
): Promise<void> {
  await apiClient.post(`/v1/storage/trash/${id}/restore`, { strategy })
}

export async function purgeTrashItem(id: number): Promise<void> {
  await apiClient.delete(`/v1/storage/trash/${id}`)
}

export async function emptyTrash(): Promise<number> {
  const data = unwrapData<{ purged: number }>(await apiClient.delete('/v1/storage/trash'))
  return data?.purged ?? 0
}

// ─── Shares ───

export async function fetchShares(path: string): Promise<StorageShare[]> {
  const data = unwrapData<StorageShare[]>(await apiClient.get('/v1/storage/shares', { params: { path } }))
  return Array.isArray(data) ? data : []
}

export async function createShare(input: {
  path: string
  password?: string | null
  expires_in?: number | null
  max_downloads?: number | null
  can_download?: boolean
}): Promise<StorageShare> {
  return unwrapData<StorageShare>(await apiClient.post('/v1/storage/shares', input)) as StorageShare
}

export async function revokeShare(id: number): Promise<void> {
  await apiClient.delete(`/v1/storage/shares/${id}`)
}

// ─── Audit ───

export async function fetchAudit(
  filters: Record<string, unknown> = {},
): Promise<{ data: StorageAuditEntry[]; meta: any }> {
  const res = await apiClient.get('/v1/storage/audit', { params: filters })
  const payload = res.data ?? {}
  return { data: Array.isArray(payload.data) ? payload.data : [], meta: payload.meta ?? {} }
}

// ─── Admin ───

export async function fetchAdminQuotas(
  search = '',
): Promise<{ data: AdminQuotaRow[]; default_quota_bytes: number; meta: any }> {
  const res = await apiClient.get('/v1/admin/storage/quotas', { params: { search } })
  const payload = res.data ?? {}
  return {
    data: Array.isArray(payload.data) ? payload.data : [],
    default_quota_bytes: payload.default_quota_bytes ?? 0,
    meta: payload.meta ?? {},
  }
}

export async function updateUserQuota(userId: number, quotaBytes: number | null): Promise<void> {
  await apiClient.put(`/v1/admin/storage/quotas/${userId}`, { quota_bytes: quotaBytes })
}

export async function fetchAdminSettings(): Promise<StorageSettings> {
  return unwrapData<StorageSettings>(await apiClient.get('/v1/admin/storage/settings')) as StorageSettings
}

export async function updateAdminSettings(settings: Partial<StorageSettings>): Promise<StorageSettings> {
  return unwrapData<StorageSettings>(await apiClient.put('/v1/admin/storage/settings', settings)) as StorageSettings
}

export async function fetchAdminAudit(
  filters: Record<string, unknown> = {},
): Promise<{ data: StorageAuditEntry[]; meta: any }> {
  const res = await apiClient.get('/v1/admin/storage/audit', { params: filters })
  const payload = res.data ?? {}
  return { data: Array.isArray(payload.data) ? payload.data : [], meta: payload.meta ?? {} }
}

// ─── Uploads (chunked) ───

export async function createUploadSession(input: {
  path: string
  name: string
  size: number
  checksum?: string
  overwrite?: boolean
}): Promise<UploadSession> {
  return unwrapData<UploadSession>(await apiClient.post('/v1/storage/uploads', input)) as UploadSession
}

export async function uploadChunk(uploadId: string, index: number, chunk: Blob, checksum?: string): Promise<void> {
  await apiClient.put(`/v1/storage/uploads/${uploadId}/chunks/${index}`, chunk, {
    headers: { 'Content-Type': 'application/octet-stream', ...(checksum ? { 'X-Chunk-Checksum': checksum } : {}) },
    timeout: 0,
  })
}

export async function getUploadSession(uploadId: string): Promise<UploadSession> {
  return unwrapData<UploadSession>(await apiClient.get(`/v1/storage/uploads/${uploadId}`)) as UploadSession
}

export async function completeUpload(uploadId: string, checksum?: string): Promise<void> {
  await apiClient.post(`/v1/storage/uploads/${uploadId}/complete`, checksum ? { checksum } : {})
}

export async function cancelUpload(uploadId: string): Promise<void> {
  await apiClient.delete(`/v1/storage/uploads/${uploadId}`)
}

export async function uploadSimple(file: File, folder: string, relativePath: string, overwrite = false): Promise<void> {
  const form = new FormData()
  form.append('file', file)
  form.append('path', folder)
  form.append('relative_path', relativePath)
  if (overwrite) form.append('overwrite', '1')
  await apiClient.post('/v1/storage/upload', form, { timeout: 0 })
}

export type { PreviewStatus }

// ─── Public share (anonymous) ───

export const publicShareApi = {
  async info(token: string): Promise<PublicShareInfo> {
    return unwrapData<PublicShareInfo>(await apiClient.get(`/v1/storage/public/share/${token}`)) as PublicShareInfo
  },
  async unlock(token: string, password: string): Promise<string> {
    const data = unwrapData<{ access_token: string }>(
      await apiClient.post(`/v1/storage/public/share/${token}/unlock`, { password }),
    )
    return data?.access_token ?? ''
  },
  rawUrl(token: string, access?: string | null): string {
    const query = access ? `?access=${encodeURIComponent(access)}` : ''
    return `/api/v1/storage/public/share/${token}/raw${query}`
  },
  downloadUrl(token: string, access?: string | null): string {
    const query = access ? `?access=${encodeURIComponent(access)}` : ''
    return `/api/v1/storage/public/share/${token}/download${query}`
  },
  previewUrl(token: string, access?: string | null): string {
    const query = access ? `?access=${encodeURIComponent(access)}` : ''
    return `/api/v1/storage/public/share/${token}/preview${query}`
  },
  async archive(token: string, access?: string | null): Promise<ArchiveListing> {
    const url = `/v1/storage/public/share/${token}/archive${access ? `?access=${encodeURIComponent(access)}` : ''}`
    return unwrapData<ArchiveListing>(await apiClient.get(url)) as ArchiveListing
  },
}
