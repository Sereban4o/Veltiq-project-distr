import apiClient from '@/modules/shared/api/apiClient'
import type {
  KbSearchResult,
  Page,
  PageComment,
  PageDiffResponse,
  PageNode,
  PagePermissionEntry,
  PageTemplate,
  PageVersion,
  Space,
} from '@/types'

export interface PageCreatePayload {
  title: string
  body?: string
  labels?: string[]
  parent_id?: number | null
  status?: 'draft' | 'published' | 'archived'
  template_id?: number | null
}

export interface PageUpdatePayload {
  title?: string
  body?: string
  labels?: string[]
  status?: 'draft' | 'published' | 'archived'
  version_summary?: string
}

export interface PageShare {
  id: number
  has_password: boolean
  expires_at?: string | null
  revoked_at?: string | null
  created_at?: string | null
  active: boolean
}

export interface PageShareCreated {
  id: number
  token: string
  path: string
  expires_at?: string | null
  has_password: boolean
}

export interface PublicPage {
  title: string
  body?: string | null
  labels?: string[]
  space?: string | null
  updated_at?: string | null
}

export interface PageBacklink {
  id: number
  title: string
  space_key: string
  space_name?: string | null
  link: string
}

export const knowledgebaseApi = {
  pageShares: (pageId: number) =>
    apiClient.get<{ data: PageShare[] }>(`/v1/knowledgebase/pages/${pageId}/shares`).then((r) => r.data.data),

  createPageShare: (pageId: number, payload: { expires_at?: string | null; password?: string | null }) =>
    apiClient
      .post<{ data: PageShareCreated }>(`/v1/knowledgebase/pages/${pageId}/shares`, payload)
      .then((r) => r.data.data),

  revokePageShare: (shareId: number) => apiClient.delete(`/v1/knowledgebase/shares/${shareId}`),

  pageBacklinks: (pageId: number) =>
    apiClient.get<{ data: PageBacklink[] }>(`/v1/knowledgebase/pages/${pageId}/backlinks`).then((r) => r.data.data),

  publicPage: (token: string, password?: string) =>
    apiClient
      .get<{ data: PublicPage }>(`/v1/public/pages/${token}`, {
        params: password ? { password } : undefined,
      })
      .then((r) => r.data.data),
  // Spaces
  listSpaces: () => apiClient.get<Space[]>('/v1/knowledgebase/spaces').then((r) => r.data),

  // Tree
  getTree: (spaceKey: string) =>
    apiClient.get<PageNode[]>(`/v1/knowledgebase/spaces/${spaceKey}/tree`).then((r) => r.data),

  // Pages
  getPage: (id: number) => apiClient.get<Page>(`/v1/knowledgebase/pages/${id}`).then((r) => r.data),

  createPage: (spaceKey: string, payload: PageCreatePayload) =>
    apiClient.post<Page>(`/v1/knowledgebase/spaces/${spaceKey}/pages`, payload).then((r) => r.data),

  updatePage: (id: number, payload: PageUpdatePayload) =>
    apiClient.patch<Page>(`/v1/knowledgebase/pages/${id}`, payload).then((r) => r.data),

  deletePage: (id: number) => apiClient.delete(`/v1/knowledgebase/pages/${id}`),

  movePage: (id: number, payload: { parent_id: number | null; position: number }) =>
    apiClient.patch<Page>(`/v1/knowledgebase/pages/${id}/move`, payload).then((r) => r.data),

  publishPage: (id: number) => apiClient.post<Page>(`/v1/knowledgebase/pages/${id}/publish`).then((r) => r.data),

  updateLabels: (id: number, labels: string[]) =>
    apiClient.patch<Page>(`/v1/knowledgebase/pages/${id}/labels`, { labels }).then((r) => r.data),

  // Versions
  getVersions: (id: number) =>
    apiClient.get<PageVersion[]>(`/v1/knowledgebase/pages/${id}/versions`).then((r) => r.data),

  getDiff: (id: number, from: number, to: number) =>
    apiClient.get<PageDiffResponse>(`/v1/knowledgebase/pages/${id}/diff`, { params: { from, to } }).then((r) => r.data),

  restoreVersion: (id: number, version: number) =>
    apiClient.post<Page>(`/v1/knowledgebase/pages/${id}/versions/${version}/restore`).then((r) => r.data),

  // Comments
  getComments: (id: number) =>
    apiClient.get<PageComment[]>(`/v1/knowledgebase/pages/${id}/comments`).then((r) => r.data),

  addComment: (id: number, body: string) =>
    apiClient.post<PageComment>(`/v1/knowledgebase/pages/${id}/comments`, { body }).then((r) => r.data),

  updateComment: (id: number, commentId: number, body: string) =>
    apiClient.patch<PageComment>(`/v1/knowledgebase/pages/${id}/comments/${commentId}`, { body }).then((r) => r.data),

  deleteComment: (id: number, commentId: number) =>
    apiClient.delete(`/v1/knowledgebase/pages/${id}/comments/${commentId}`),

  resolveComment: (id: number, commentId: number, resolved: boolean) =>
    apiClient
      .patch<PageComment>(`/v1/knowledgebase/pages/${id}/comments/${commentId}/resolve`, { resolved })
      .then((r) => r.data),

  // Permissions
  getPermissions: (id: number) =>
    apiClient
      .get<{ permissions: PagePermissionEntry[]; can_manage: boolean }>(`/v1/knowledgebase/pages/${id}/permissions`)
      .then((r) => r.data),

  updatePermissions: (id: number, permissions: { user_id: number; permission: 'viewer' | 'editor' }[]) =>
    apiClient
      .put<{ permissions: PagePermissionEntry[] }>(`/v1/knowledgebase/pages/${id}/permissions`, {
        permissions,
      })
      .then((r) => r.data),

  // Templates
  listTemplates: (spaceKey: string) =>
    apiClient.get<PageTemplate[]>(`/v1/knowledgebase/spaces/${spaceKey}/templates`).then((r) => r.data),

  createTemplate: (spaceKey: string, payload: Partial<PageTemplate>) =>
    apiClient.post<PageTemplate>(`/v1/knowledgebase/spaces/${spaceKey}/templates`, payload).then((r) => r.data),

  updateTemplate: (id: number, payload: Partial<PageTemplate>) =>
    apiClient.patch<PageTemplate>(`/v1/knowledgebase/templates/${id}`, payload).then((r) => r.data),

  deleteTemplate: (id: number) => apiClient.delete(`/v1/knowledgebase/templates/${id}`),

  // Search
  search: (query: string, space?: string) =>
    apiClient
      .get<{ source: string; results: KbSearchResult[] }>('/v1/knowledgebase/search', {
        params: { q: query, ...(space ? { space } : {}) },
      })
      .then((r) => r.data),

  // Image upload — returns the browser-usable URL including an auth token so
  // <img> tags load without headers.
  uploadImage: async (file: File): Promise<string> => {
    const formData = new FormData()
    formData.append('file', file)
    const uploaded = await apiClient.post<{ id: number }>('/v1/files/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    const token = typeof localStorage !== 'undefined' ? localStorage.getItem('auth_token') : ''
    const tokenParam = token ? `?token=${encodeURIComponent(token)}` : ''
    return `/api/v1/files/${uploaded.data.id}${tokenParam}`
  },

  // Export downloads (token in the URL because the browser opens them directly).
  exportUrl: (id: number, format: 'pdf' | 'markdown') => {
    const token = typeof localStorage !== 'undefined' ? localStorage.getItem('auth_token') : ''
    const tokenParam = token ? `?token=${encodeURIComponent(token)}` : ''
    return `/api/v1/knowledgebase/pages/${id}/export/${format}${tokenParam}`
  },

  // Download an export as a file, naming it after the page title when given.
  downloadExport: async (id: number, format: 'pdf' | 'markdown', filename?: string) => {
    const res = await apiClient.get(`/v1/knowledgebase/pages/${id}/export/${format}`, {
      responseType: 'blob',
    })
    const url = URL.createObjectURL(res.data as Blob)
    const extension = format === 'pdf' ? 'pdf' : 'md'
    const link = document.createElement('a')
    link.href = url
    link.download = `${filename || 'page'}.${extension}`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  },
}

export default knowledgebaseApi
