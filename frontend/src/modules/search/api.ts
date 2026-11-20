import apiClient from '@/modules/shared/api/apiClient'
import type { SearchResponse, SearchType } from './types'

export const searchApi = {
  search: async (params: {
    q: string
    types?: SearchType[]
    per_page?: number
    page?: number
  }): Promise<SearchResponse> => {
    const res = await apiClient.get<SearchResponse>('/v1/search', {
      params: {
        q: params.q,
        types: params.types?.length ? params.types.join(',') : undefined,
        per_page: params.per_page,
        page: params.page,
      },
    })

    return res.data
  },
}
