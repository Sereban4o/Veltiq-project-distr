import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Box,
  CircularProgress,
  Dialog,
  DialogContent,
  IconButton,
  InputAdornment,
  TextField,
  Typography,
} from '@mui/material'
import { Search as SearchIcon, Close as CloseIcon } from '@mui/icons-material'
import { useGlobalSearch } from '../hooks/useGlobalSearch'
import SearchResults from './SearchResults'
import type { SearchHit } from '../types'

interface SearchDialogProps {
  open: boolean
  onClose: () => void
}

/** Command-palette style global search (Ctrl/Cmd+K). */
export default function SearchDialog({ open, onClose }: SearchDialogProps) {
  const navigate = useNavigate()
  const inputRef = useRef<HTMLInputElement>(null)
  const [query, setQuery] = useState('')
  const { data, isFetching, isReady } = useGlobalSearch(query)

  useEffect(() => {
    if (open) {
      const timer = setTimeout(() => inputRef.current?.focus(), 50)
      return () => clearTimeout(timer)
    }
    setQuery('')
  }, [open])

  const handleSelect = (hit: SearchHit) => {
    if (hit.url) navigate(hit.url)
    onClose()
  }

  const handleShowAll = () => {
    if (query.trim().length >= 2) navigate(`/search?q=${encodeURIComponent(query.trim())}`)
    onClose()
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm" aria-label="Глобальный поиск">
      <DialogContent sx={{ p: 0 }}>
        <TextField
          inputRef={inputRef}
          fullWidth
          autoComplete="off"
          placeholder="Поиск по проекту…"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter') handleShowAll()
            if (event.key === 'Escape') onClose()
          }}
          InputProps={{
            startAdornment: (
              <InputAdornment position="start">
                <SearchIcon fontSize="small" />
              </InputAdornment>
            ),
            endAdornment: isFetching ? (
              <InputAdornment position="end">
                <CircularProgress size={16} />
              </InputAdornment>
            ) : (
              <InputAdornment position="end">
                <IconButton size="small" onClick={onClose} aria-label="Закрыть поиск">
                  <CloseIcon fontSize="small" />
                </IconButton>
              </InputAdornment>
            ),
          }}
          sx={{ '& fieldset': { border: 'none' } }}
        />

        <Box sx={{ maxHeight: '60vh', overflowY: 'auto', pb: 1 }}>
          {!isReady && (
            <Typography sx={{ px: 2, py: 3, textAlign: 'center', color: 'text.secondary', fontSize: '0.85rem' }}>
              Введите минимум 2 символа
            </Typography>
          )}

          {isReady && !isFetching && data && Object.values(data.groups ?? {}).every((g) => !g?.hits?.length) && (
            <Typography sx={{ px: 2, py: 3, textAlign: 'center', color: 'text.secondary', fontSize: '0.85rem' }}>
              Ничего не найдено
            </Typography>
          )}

          {isReady && <SearchResults groups={data?.groups} onSelect={handleSelect} />}

          {isReady && data && Object.values(data.groups ?? {}).some((g) => g?.hits?.length) && (
            <Typography
              onClick={handleShowAll}
              sx={{ px: 2, pt: 1, color: 'primary.main', cursor: 'pointer', fontSize: '0.85rem' }}
            >
              Показать все результаты →
            </Typography>
          )}
        </Box>
      </DialogContent>
    </Dialog>
  )
}
