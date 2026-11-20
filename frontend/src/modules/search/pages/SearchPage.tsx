import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Box, Button, Chip, CircularProgress, InputAdornment, Stack, TextField, Typography } from '@mui/material'
import { Search as SearchIcon } from '@mui/icons-material'
import { useGlobalSearch } from '../hooks/useGlobalSearch'
import SearchResults from '../components/SearchResults'
import type { SearchHit, SearchType } from '../types'
import { SEARCH_TYPES, SEARCH_TYPE_LABELS } from '../types'

const PER_PAGE = 20

/** Full-page global search with a type filter and paged results. */
export default function SearchPage() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [query, setQuery] = useState(() => params.get('q') ?? '')
  const page = Math.max(1, Number(params.get('page') ?? '1'))

  const typesParam = params.get('types')
  const types = typesParam
    ? (typesParam.split(',').filter((t) => (SEARCH_TYPES as string[]).includes(t)) as SearchType[])
    : undefined

  const { data, isFetching, isReady } = useGlobalSearch(query, types, page)

  // Keep the URL in sync with the query so results are shareable/refreshable.
  useEffect(() => {
    const trimmed = query.trim()
    const next = new URLSearchParams()
    if (trimmed) next.set('q', trimmed)
    if (types?.length) next.set('types', types.join(','))
    if (page > 1) next.set('page', String(page))
    setParams(next, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query, typesParam, page])

  const toggleType = (type: SearchType) => {
    const current = types ?? SEARCH_TYPES
    const next = current.includes(type) ? current.filter((t) => t !== type) : [...current, type]
    const nextParams = new URLSearchParams(params)
    if (next.length === SEARCH_TYPES.length) nextParams.delete('types')
    else nextParams.set('types', next.join(','))
    nextParams.delete('page')
    setParams(nextParams, { replace: true })
  }

  const hasAnyHit = Object.values(data?.groups ?? {}).some((group) => (group?.hits?.length ?? 0) > 0)

  const handleSelect = (hit: SearchHit) => {
    if (hit.url) navigate(hit.url)
  }

  return (
    <Box sx={{ maxWidth: 900, mx: 'auto' }}>
      <Typography variant="h5" sx={{ mb: 2, fontWeight: 700 }}>
        Поиск
      </Typography>

      <TextField
        fullWidth
        autoFocus
        autoComplete="off"
        placeholder="Поиск по задачам, страницам, файлам, почте и чату"
        value={query}
        onChange={(event) => setQuery(event.target.value)}
        InputProps={{
          startAdornment: (
            <InputAdornment position="start">
              <SearchIcon />
            </InputAdornment>
          ),
          endAdornment: isFetching ? (
            <InputAdornment position="end">
              <CircularProgress size={18} />
            </InputAdornment>
          ) : undefined,
        }}
      />

      <Stack direction="row" spacing={1} sx={{ mt: 2, flexWrap: 'wrap', gap: 1 }}>
        {SEARCH_TYPES.map((type) => (
          <Chip
            key={type}
            label={SEARCH_TYPE_LABELS[type]}
            color={(types ?? SEARCH_TYPES).includes(type) ? 'primary' : 'default'}
            variant={(types ?? SEARCH_TYPES).includes(type) ? 'filled' : 'outlined'}
            onClick={() => toggleType(type)}
          />
        ))}
      </Stack>

      <Box sx={{ mt: 2 }}>
        {!isReady && (
          <Typography color="text.secondary" sx={{ py: 4, textAlign: 'center' }}>
            Введите минимум 2 символа
          </Typography>
        )}

        {isReady && !isFetching && !hasAnyHit && (
          <Typography color="text.secondary" sx={{ py: 4, textAlign: 'center' }}>
            Ничего не найдено
          </Typography>
        )}

        {isReady && <SearchResults groups={data?.groups} onSelect={handleSelect} />}
      </Box>

      {isReady && hasAnyHit && (
        <Box sx={{ display: 'flex', justifyContent: 'space-between', mt: 2 }}>
          <Button
            disabled={page <= 1}
            onClick={() => setParams({ ...Object.fromEntries(params), page: String(page - 1) })}
          >
            Назад
          </Button>
          <Typography sx={{ alignSelf: 'center', color: 'text.secondary', fontSize: '0.85rem' }}>
            Страница {page}
          </Typography>
          <Button
            onClick={() => setParams({ ...Object.fromEntries(params), page: String(page + 1) })}
            disabled={(data?.per_page ?? PER_PAGE) > 0 && !hasAnyHit}
          >
            Далее
          </Button>
        </Box>
      )}
    </Box>
  )
}
