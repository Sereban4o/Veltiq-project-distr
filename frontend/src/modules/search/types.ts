export type SearchType = 'issues' | 'pages' | 'files' | 'mail' | 'chat'

export interface SearchHit {
  id: number
  title: string
  snippet: string
  url: string
  meta: Record<string, any>
}

export interface SearchGroup {
  total: number
  hits: SearchHit[]
}

export interface SearchResponse {
  query: string
  types: SearchType[]
  page: number
  per_page: number
  groups: Partial<Record<SearchType, SearchGroup>>
}

export const SEARCH_TYPE_LABELS: Record<SearchType, string> = {
  issues: 'Задачи',
  pages: 'Страницы',
  files: 'Файлы',
  mail: 'Почта',
  chat: 'Чат',
}

export const SEARCH_TYPES: SearchType[] = ['issues', 'pages', 'files', 'mail', 'chat']
