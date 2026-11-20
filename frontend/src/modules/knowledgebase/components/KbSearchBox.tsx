import { useEffect, useRef, useState } from 'react'
import { Box, TextField, Paper, List, ListItemButton, Typography, Chip, CircularProgress } from '@mui/material'
import { Search as SearchIcon } from '@mui/icons-material'
import knowledgebaseApi from '../api/knowledgebaseApi'
import type { KbSearchResult } from '@/types'

interface KbSearchBoxProps {
  space?: string
  onNavigate: (spaceKey: string, pageId: number) => void
}

export default function KbSearchBox({ space, onNavigate }: KbSearchBoxProps) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<KbSearchResult[]>([])
  const [loading, setLoading] = useState(false)
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement | null>(null)

  useEffect(() => {
    if (query.trim().length < 2) {
      setResults([])
      setLoading(false)
      return
    }

    setLoading(true)
    const handle = setTimeout(() => {
      knowledgebaseApi
        .search(query.trim(), space)
        .then((data) => setResults(data.results))
        .catch(() => setResults([]))
        .finally(() => setLoading(false))
    }, 300)

    return () => clearTimeout(handle)
  }, [query, space])

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  return (
    <Box ref={containerRef} sx={{ position: 'relative', width: '100%' }}>
      <TextField
        fullWidth
        size="small"
        placeholder="Поиск по базе знаний..."
        value={query}
        onChange={(e) => {
          setQuery(e.target.value)
          setOpen(true)
        }}
        onFocus={() => setOpen(true)}
        slotProps={{
          input: {
            startAdornment: <SearchIcon fontSize="small" sx={{ mr: 1, color: 'text.secondary' }} />,
          },
        }}
      />

      {open && query.trim().length >= 2 && (
        <Paper
          elevation={6}
          sx={{ position: 'absolute', zIndex: 1300, mt: 0.5, left: 0, right: 0, maxHeight: 360, overflow: 'auto' }}
        >
          {loading ? (
            <Box sx={{ display: 'flex', justifyContent: 'center', py: 2 }}>
              <CircularProgress size={20} />
            </Box>
          ) : results.length === 0 ? (
            <Typography variant="body2" color="text.secondary" sx={{ p: 2 }}>
              Ничего не найдено
            </Typography>
          ) : (
            <List disablePadding>
              {results.map((result) => (
                <ListItemButton
                  key={result.id}
                  onClick={() => {
                    onNavigate(result.space_key, result.id)
                    setOpen(false)
                    setQuery('')
                  }}
                >
                  <Box sx={{ width: '100%' }}>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                      <Typography variant="subtitle2">{result.title}</Typography>
                      <Chip label={result.space_name} size="small" />
                    </Box>
                    {result.excerpt && (
                      <Typography variant="caption" color="text.secondary" display="block">
                        {result.excerpt}
                      </Typography>
                    )}
                  </Box>
                </ListItemButton>
              ))}
            </List>
          )}
        </Paper>
      )}
    </Box>
  )
}
