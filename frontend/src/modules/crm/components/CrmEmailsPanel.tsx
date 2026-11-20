import { useState } from 'react'
import {
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  Stack,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import { Delete as DeleteIcon, Link as LinkIcon, Search as SearchIcon } from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { crmApi } from '../api'
import type { CrmEmail } from '../types'

function formatDate(value: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })
}

export default function CrmEmailsPanel({ entity, id }: { entity: string; id: number }) {
  const queryClient = useQueryClient()
  const [pickerOpen, setPickerOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [results, setResults] = useState<CrmEmail[]>([])
  const [searching, setSearching] = useState(false)

  const { data: emails = [], isLoading } = useQuery<CrmEmail[]>({
    queryKey: ['crm-emails', entity, id],
    queryFn: () => crmApi.emails(entity, id),
  })

  const reload = () => queryClient.invalidateQueries({ queryKey: ['crm-emails', entity, id] })

  const searchMail = async () => {
    setSearching(true)
    try {
      setResults(await crmApi.searchMail(search.trim()))
    } finally {
      setSearching(false)
    }
  }

  const attach = async (message: CrmEmail) => {
    await crmApi.attachEmail(entity, id, message.id)
    setPickerOpen(false)
    setResults([])
    setSearch('')
    reload()
  }

  const detach = async (message: CrmEmail) => {
    await crmApi.detachEmail(entity, id, message.id)
    reload()
  }

  return (
    <Stack spacing={2}>
      <Box>
        <Button
          variant="outlined"
          startIcon={<LinkIcon />}
          onClick={() => setPickerOpen(true)}
          sx={{ textTransform: 'none' }}
        >
          Привязать письмо
        </Button>
      </Box>

      {isLoading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 3 }}>
          <CircularProgress size={28} />
        </Box>
      ) : emails.length === 0 ? (
        <Typography sx={{ color: 'text.secondary', fontSize: '0.85rem' }}>
          Писем пока нет. Письма привязываются автоматически по email или вручную.
        </Typography>
      ) : (
        <Stack spacing={1}>
          {emails.map((email) => (
            <Box
              key={email.id}
              sx={{
                display: 'flex',
                alignItems: 'center',
                gap: 1,
                p: 1,
                border: 1,
                borderColor: 'divider',
                borderRadius: 1,
              }}
            >
              <Box sx={{ flex: 1, minWidth: 0 }}>
                <Typography sx={{ fontSize: '0.85rem', fontWeight: 600 }}>{email.subject || '(без темы)'}</Typography>
                <Typography sx={{ fontSize: '0.75rem', color: 'text.secondary' }}>
                  {email.from_name || email.from_email || '—'} · {formatDate(email.received_at ?? email.sent_at)}
                </Typography>
              </Box>
              <Tooltip title="Отвязать">
                <IconButton size="small" onClick={() => detach(email)}>
                  <DeleteIcon fontSize="small" />
                </IconButton>
              </Tooltip>
            </Box>
          ))}
        </Stack>
      )}

      <Dialog open={pickerOpen} onClose={() => setPickerOpen(false)} fullWidth maxWidth="sm">
        <DialogTitle>Привязать письмо</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <Box sx={{ display: 'flex', gap: 1 }}>
              <TextField
                size="small"
                fullWidth
                placeholder="Поиск по письмам..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault()
                    searchMail()
                  }
                }}
                InputProps={{ startAdornment: <SearchIcon fontSize="small" sx={{ mr: 1, color: 'text.secondary' }} /> }}
              />
              <Button variant="outlined" onClick={searchMail} disabled={searching} sx={{ textTransform: 'none' }}>
                Найти
              </Button>
            </Box>

            {searching && <CircularProgress size={24} />}

            {results.length === 0 && !searching && (
              <Typography sx={{ color: 'text.secondary', fontSize: '0.85rem' }}>
                Введите запрос и нажмите «Найти»
              </Typography>
            )}

            <Stack spacing={1} sx={{ maxHeight: 320, overflow: 'auto' }}>
              {results.map((message) => (
                <Box
                  key={message.id}
                  sx={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 1,
                    p: 1,
                    border: 1,
                    borderColor: 'divider',
                    borderRadius: 1,
                    cursor: 'pointer',
                    '&:hover': { bgcolor: 'action.hover' },
                  }}
                  onClick={() => attach(message)}
                >
                  <Box sx={{ flex: 1, minWidth: 0 }}>
                    <Typography sx={{ fontSize: '0.85rem', fontWeight: 600 }}>
                      {message.subject || '(без темы)'}
                    </Typography>
                    <Typography sx={{ fontSize: '0.75rem', color: 'text.secondary' }}>
                      {message.from_name || message.from_email} · {formatDate(message.received_at ?? message.sent_at)}
                    </Typography>
                  </Box>
                </Box>
              ))}
            </Stack>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setPickerOpen(false)} sx={{ textTransform: 'none' }}>
            Закрыть
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}
