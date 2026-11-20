import apiClient from '@/modules/shared/api/apiClient'
import { unwrapData, unwrapList } from '@/modules/shared/utils/apiResponse'
import type {
  BulkAction,
  BulkResult,
  FolderFromSenderResult,
  MailAccountMember,
  MailAccountSummary,
  MailContact,
  MailFolderRecord,
  MailFilterRule,
  MailMessage,
  MailSignature,
  MailSuggestion,
  MailTemplate,
  MailThreadDetail,
  MailThreadSummary,
  MailFolder,
} from './types'

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface MessageQuery {
  folder?: MailFolder
  folder_id?: number
  q?: string
  group?: 'thread'
  per_page?: number
  page?: number
  account_id?: number
}

function normalizePaginated<T>(payload: unknown): Paginated<T> {
  const source: any = (payload as any)?.data ?? payload
  const raw: any = source?.data && !Array.isArray(source.data) ? source.data : source
  const items = Array.isArray(raw?.data) ? raw.data : Array.isArray(raw) ? raw : []

  return {
    data: items as T[],
    current_page: Number(raw?.current_page ?? 1),
    last_page: Number(raw?.last_page ?? 1),
    per_page: Number(raw?.per_page ?? items.length),
    total: Number(raw?.total ?? items.length),
  }
}

function buildMessageParams(query: MessageQuery): Record<string, string | number> {
  const params: Record<string, string | number> = { per_page: query.per_page ?? 50 }
  if (query.folder_id != null) params.folder_id = query.folder_id
  else if (query.folder) params.folder = query.folder
  if (query.q) params.q = query.q
  if (query.page) params.page = query.page
  if (query.account_id != null) params.account_id = query.account_id
  return params
}

export const mailApi = {
  // ── Mailboxes (own + shared team inboxes) ──
  async listAccounts(): Promise<MailAccountSummary[]> {
    return unwrapList<MailAccountSummary>(await apiClient.get('/v1/mail/accounts'))
  },

  async listAccountMembers(): Promise<MailAccountMember[]> {
    return unwrapList<MailAccountMember>(await apiClient.get('/v1/mail/settings/members'))
  },

  async addAccountMember(userId: number, canSend = false): Promise<void> {
    await apiClient.post('/v1/mail/settings/members', { user_id: userId, can_send: canSend })
  },

  async removeAccountMember(userId: number): Promise<void> {
    await apiClient.delete(`/v1/mail/settings/members/${userId}`)
  },

  // ── Folders ──
  async listFolders(): Promise<MailFolderRecord[]> {
    return unwrapList<MailFolderRecord>(await apiClient.get('/v1/mail/folders'))
  },

  async createFolder(name: string): Promise<MailFolderRecord> {
    return unwrapData<MailFolderRecord>(await apiClient.post('/v1/mail/folders', { name })) as MailFolderRecord
  },

  async renameFolder(id: number, name: string): Promise<MailFolderRecord> {
    return unwrapData<MailFolderRecord>(await apiClient.patch(`/v1/mail/folders/${id}`, { name })) as MailFolderRecord
  },

  async deleteFolder(id: number): Promise<void> {
    await apiClient.delete(`/v1/mail/folders/${id}`)
  },

  async folderFromSender(senderEmail: string, name?: string): Promise<FolderFromSenderResult> {
    const res = await apiClient.post('/v1/mail/folders/from-sender', {
      sender_email: senderEmail,
      ...(name ? { name } : {}),
    })
    return res.data as FolderFromSenderResult
  },

  // ── Messages ──
  async listMessages(query: MessageQuery): Promise<Paginated<MailMessage>> {
    const res = await apiClient.get('/v1/mail/messages', { params: buildMessageParams(query) })
    return normalizePaginated<MailMessage>(res.data)
  },

  async listThreads(query: MessageQuery): Promise<Paginated<MailThreadSummary>> {
    const res = await apiClient.get('/v1/mail/messages', {
      params: { ...buildMessageParams(query), group: 'thread' },
    })
    return normalizePaginated<MailThreadSummary>(res.data)
  },

  async getThread(threadKey: string): Promise<MailThreadDetail> {
    const res = await apiClient.get(`/v1/mail/threads/${threadKey}`)
    return unwrapData<MailThreadDetail>(res.data) as MailThreadDetail
  },

  async getMessage(id: number): Promise<MailMessage> {
    return unwrapData<MailMessage>(await apiClient.get(`/v1/mail/messages/${id}`)) as MailMessage
  },

  async updateMessage(id: number, patch: Record<string, unknown>): Promise<MailMessage> {
    return unwrapData<MailMessage>(await apiClient.patch(`/v1/mail/messages/${id}`, patch)) as MailMessage
  },

  async deleteMessage(id: number): Promise<void> {
    await apiClient.delete(`/v1/mail/messages/${id}`)
  },

  async bulkAction(payload: {
    action: BulkAction
    ids: number[]
    folder?: MailFolder
    folder_id?: number
  }): Promise<BulkResult> {
    const res = await apiClient.post('/v1/mail/messages/bulk', payload)
    return res.data as BulkResult
  },

  // ── Contacts ──
  async listContacts(search: string, perPage = 25): Promise<MailContact[]> {
    return unwrapList<MailContact>(await apiClient.get('/v1/mail/contacts', { params: { search, per_page: perPage } }))
  },

  async suggestRecipients(q: string, limit = 10): Promise<MailSuggestion[]> {
    return unwrapList<MailSuggestion>(await apiClient.get('/v1/mail/contacts/suggest', { params: { q, limit } }))
  },

  // ── Signatures ──
  async listSignatures(): Promise<MailSignature[]> {
    return unwrapList<MailSignature>(await apiClient.get('/v1/mail/signatures'))
  },

  async createSignature(payload: Partial<MailSignature>): Promise<MailSignature> {
    return unwrapData<MailSignature>(await apiClient.post('/v1/mail/signatures', payload)) as MailSignature
  },

  async updateSignature(id: number, payload: Partial<MailSignature>): Promise<MailSignature> {
    return unwrapData<MailSignature>(await apiClient.patch(`/v1/mail/signatures/${id}`, payload)) as MailSignature
  },

  async deleteSignature(id: number): Promise<void> {
    await apiClient.delete(`/v1/mail/signatures/${id}`)
  },

  // ── Templates ──
  async listTemplates(): Promise<MailTemplate[]> {
    return unwrapList<MailTemplate>(await apiClient.get('/v1/mail/templates'))
  },

  async createTemplate(payload: Partial<MailTemplate>): Promise<MailTemplate> {
    return unwrapData<MailTemplate>(await apiClient.post('/v1/mail/templates', payload)) as MailTemplate
  },

  async updateTemplate(id: number, payload: Partial<MailTemplate>): Promise<MailTemplate> {
    return unwrapData<MailTemplate>(await apiClient.patch(`/v1/mail/templates/${id}`, payload)) as MailTemplate
  },

  async deleteTemplate(id: number): Promise<void> {
    await apiClient.delete(`/v1/mail/templates/${id}`)
  },

  // ── Rules (list only; full CRUD lives in MailRulesDialog) ──
  async listRules(): Promise<MailFilterRule[]> {
    return unwrapList<MailFilterRule>(await apiClient.get('/v1/mail/rules'))
  },
}

export default mailApi
