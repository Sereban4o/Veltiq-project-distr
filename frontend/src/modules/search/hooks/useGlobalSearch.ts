import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { searchApi } from '../api'
import type { SearchResponse, SearchType } from '../types'

/** Debounce a value (used for the search input). */
export function useDebouncedValue<T>(value: T, delay = 350): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(timer)
  }, [value, delay])

  return debounced
}

/**
 * Permission-aware global search. Returns per-type groups; totals are
 * approximate (the backend filters Meilisearch candidates by ACL), so the UI
 * paginates per type instead of trusting an exact count.
 */
export function useGlobalSearch(query: string, types?: SearchType[], page = 1) {
  const debounced = useDebouncedValue(query.trim(), 350)
  const typesKey = types?.join(',') ?? ''

  const result = useQuery<SearchResponse>({
    queryKey: ['global-search', debounced, typesKey, page],
    queryFn: () => searchApi.search({ q: debounced, types, page }),
    enabled: debounced.length >= 2,
    staleTime: 30_000,
  })

  return { ...result, debounced, isReady: debounced.length >= 2 }
}
