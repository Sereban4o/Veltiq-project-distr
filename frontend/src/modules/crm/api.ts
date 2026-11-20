import apiClient from '@/modules/shared/api/apiClient'
import { unwrapData, unwrapList } from '@/modules/shared/utils/apiResponse'
import type {
  CrmActivity,
  CrmAutomationRule,
  CrmCompany,
  CrmContact,
  CrmDashboard,
  CrmDeal,
  CrmEmail,
  CrmFile,
  CrmImportResult,
  CrmReportConversion,
  CrmReportFunnelStage,
  CrmReportRevenue,
  CrmReportSummary,
  OneCCounterparty,
  PipelineStage,
} from './types'

const base = '/v1/crm'

export const crmApi = {
  dashboard: async (): Promise<CrmDashboard> =>
    unwrapData<CrmDashboard>(await apiClient.get(`${base}/dashboard`)) as CrmDashboard,

  stages: async (): Promise<PipelineStage[]> => unwrapList<PipelineStage>(await apiClient.get(`${base}/stages`)),

  companies: async (search = ''): Promise<CrmCompany[]> =>
    unwrapList<CrmCompany>(await apiClient.get(`${base}/companies`, { params: { search, per_page: 100 } })),
  company: async (id: number): Promise<CrmCompany> =>
    unwrapData<CrmCompany>(await apiClient.get(`${base}/companies/${id}`)) as CrmCompany,
  createCompany: async (payload: Partial<CrmCompany>): Promise<CrmCompany> =>
    unwrapData<CrmCompany>(await apiClient.post(`${base}/companies`, payload)) as CrmCompany,
  updateCompany: async (id: number, payload: Partial<CrmCompany>): Promise<CrmCompany> =>
    unwrapData<CrmCompany>(await apiClient.patch(`${base}/companies/${id}`, payload)) as CrmCompany,
  deleteCompany: (id: number) => apiClient.delete(`${base}/companies/${id}`),

  searchCounterpartiesOneC: async (q: string): Promise<OneCCounterparty[]> =>
    unwrapData<{ items: OneCCounterparty[] }>(
      await apiClient.get(`${base}/counterparties/search-1c`, { params: { q } }),
    )?.items ?? [],

  contacts: async (search = '', companyId?: number): Promise<CrmContact[]> =>
    unwrapList<CrmContact>(
      await apiClient.get(`${base}/contacts`, { params: { search, company_id: companyId, per_page: 100 } }),
    ),
  createContact: async (payload: Partial<CrmContact>): Promise<CrmContact> =>
    unwrapData<CrmContact>(await apiClient.post(`${base}/contacts`, payload)) as CrmContact,
  updateContact: async (id: number, payload: Partial<CrmContact>): Promise<CrmContact> =>
    unwrapData<CrmContact>(await apiClient.patch(`${base}/contacts/${id}`, payload)) as CrmContact,
  deleteContact: (id: number) => apiClient.delete(`${base}/contacts/${id}`),

  deals: async (params: { stage_id?: number; status?: string; search?: string } = {}): Promise<CrmDeal[]> =>
    unwrapList<CrmDeal>(await apiClient.get(`${base}/deals`, { params: { ...params, per_page: 200 } })),
  createDeal: async (payload: Partial<CrmDeal>): Promise<CrmDeal> =>
    unwrapData<CrmDeal>(await apiClient.post(`${base}/deals`, payload)) as CrmDeal,
  updateDeal: async (id: number, payload: Partial<CrmDeal>): Promise<CrmDeal> =>
    unwrapData<CrmDeal>(await apiClient.patch(`${base}/deals/${id}`, payload)) as CrmDeal,
  moveDeal: async (id: number, stageId: number): Promise<CrmDeal> =>
    unwrapData<CrmDeal>(await apiClient.patch(`${base}/deals/${id}/stage`, { stage_id: stageId })) as CrmDeal,
  deleteDeal: (id: number) => apiClient.delete(`${base}/deals/${id}`),

  activities: async (
    params: { filter?: string; deal_id?: number; contact_id?: number; company_id?: number } = {},
  ): Promise<CrmActivity[]> =>
    unwrapList<CrmActivity>(await apiClient.get(`${base}/activities`, { params: { ...params, per_page: 200 } })),
  createActivity: async (payload: Partial<CrmActivity>): Promise<CrmActivity> =>
    unwrapData<CrmActivity>(await apiClient.post(`${base}/activities`, payload)) as CrmActivity,
  updateActivity: async (id: number, payload: Partial<CrmActivity>): Promise<CrmActivity> =>
    unwrapData<CrmActivity>(await apiClient.patch(`${base}/activities/${id}`, payload)) as CrmActivity,
  toggleActivity: async (id: number): Promise<CrmActivity> =>
    unwrapData<CrmActivity>(await apiClient.patch(`${base}/activities/${id}/toggle`)) as CrmActivity,
  deleteActivity: (id: number) => apiClient.delete(`${base}/activities/${id}`),

  // ── Automation rules (admin) ──
  automationRules: async (): Promise<CrmAutomationRule[]> =>
    unwrapList<CrmAutomationRule>(await apiClient.get(`${base}/automation-rules`)),
  createAutomationRule: async (payload: Partial<CrmAutomationRule>): Promise<CrmAutomationRule> =>
    unwrapData<CrmAutomationRule>(await apiClient.post(`${base}/automation-rules`, payload)) as CrmAutomationRule,
  updateAutomationRule: async (id: number, payload: Partial<CrmAutomationRule>): Promise<CrmAutomationRule> =>
    unwrapData<CrmAutomationRule>(
      await apiClient.patch(`${base}/automation-rules/${id}`, payload),
    ) as CrmAutomationRule,
  deleteAutomationRule: (id: number) => apiClient.delete(`${base}/automation-rules/${id}`),

  // ── Related mail ──
  emails: async (entity: string, id: number): Promise<CrmEmail[]> =>
    unwrapList<CrmEmail>(await apiClient.get(`${base}/${entity}/${id}/emails`)),
  searchMail: async (search: string): Promise<CrmEmail[]> =>
    unwrapList<CrmEmail>(await apiClient.get('/v1/mail/messages', { params: { q: search, per_page: 25 } })),
  attachEmail: async (entity: string, id: number, messageId: number): Promise<CrmEmail[]> =>
    unwrapList<CrmEmail>(await apiClient.post(`${base}/${entity}/${id}/emails`, { mail_message_id: messageId })),
  detachEmail: async (entity: string, id: number, messageId: number): Promise<CrmEmail[]> =>
    unwrapList<CrmEmail>(await apiClient.delete(`${base}/${entity}/${id}/emails/${messageId}`)),

  // ── Linked files ──
  files: async (entity: string, id: number): Promise<CrmFile[]> =>
    unwrapList<CrmFile>(await apiClient.get(`${base}/${entity}/${id}/files`)),
  uploadFile: async (entity: string, id: number, file: File): Promise<CrmFile> => {
    const form = new FormData()
    form.append('file', file)
    return unwrapData<CrmFile>(await apiClient.post(`${base}/${entity}/${id}/files/upload`, form)) as CrmFile
  },
  detachFile: async (entity: string, id: number, fileId: number): Promise<CrmFile[]> =>
    unwrapList<CrmFile>(await apiClient.delete(`${base}/${entity}/${id}/files/${fileId}`)),

  // ── CSV export / import ──
  exportCsv: async (entity: string, params: Record<string, unknown> = {}): Promise<{ data: Blob }> =>
    apiClient.get(`${base}/${entity}/export`, { params, responseType: 'blob' }),
  importCsv: async (entity: string, formData: FormData): Promise<CrmImportResult> =>
    unwrapData<CrmImportResult>(await apiClient.post(`${base}/${entity}/import`, formData)) as CrmImportResult,

  // ── Reports ──
  reportSummary: async (): Promise<CrmReportSummary> =>
    unwrapData<CrmReportSummary>(await apiClient.get(`${base}/reports/summary`)) as CrmReportSummary,
  reportFunnel: async (): Promise<CrmReportFunnelStage[]> =>
    unwrapList<CrmReportFunnelStage>(await apiClient.get(`${base}/reports/funnel`)),
  reportConversion: async (): Promise<CrmReportConversion> =>
    unwrapData<CrmReportConversion>(await apiClient.get(`${base}/reports/conversion`)) as CrmReportConversion,
  reportRevenue: async (params: { period?: string; from?: string; to?: string } = {}): Promise<CrmReportRevenue> =>
    unwrapData<CrmReportRevenue>(await apiClient.get(`${base}/reports/revenue`, { params })) as CrmReportRevenue,
}
