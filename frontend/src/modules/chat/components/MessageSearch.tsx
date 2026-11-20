import { useEffect, useMemo, useState } from 'react'
import { format } from 'date-fns'
import { ru } from 'date-fns/locale'
import { useQuery } from '@tanstack/react-query'
import { Box, IconButton, TextField, Typography } from '@mui/material'
import { ArrowBack as BackIcon, Search as SearchIcon } from '@mui/icons-material'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapList } from '@/modules/shared/utils/apiResponse'
import { tg } from '../theme'
import type { Chat, Message } from '../types'

interface MessageSearchProps {
  chatId: number
  chats: Chat[]
  onClose: () => void
  onOpenResult: (message: Message) => void
}

/** Whole-word search over the current chat (server-side blind index). */
export default function MessageSearch({ chatId, chats, onClose, onOpenResult }: MessageSearchProps) {
  const [query, setQuery] = useState('')
  const [debounced, setDebounced] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(query.trim()), 350)
    return () => clearTimeout(timer)
  }, [query])

  const { data: results = [], isFetching } = useQuery<Message[]>({
    queryKey: ['chat-search', chatId, debounced],
    queryFn: async () => {
      const res = await apiClient.get('/v1/chat/messages/search', {
        params: { q: debounced, chat_id: chatId, per_page: 50 },
      })
      return unwrapList<Message>(res)
    },
    enabled: debounced.length >= 2,
    staleTime: 30_000,
  })

  const chatNames = useMemo(() => {
    const map = new Map<number, string>()
    chats.forEach((chat) => map.set(chat.id, chat.name ?? 'Чат'))
    return map
  }, [chats])

  return (
    <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column', minHeight: 0, bgcolor: tg.chatBg }}>
      <Box
        sx={{
          display: 'flex',
          alignItems: 'center',
          gap: 0.5,
          px: 1,
          py: 0.75,
          borderBottom: `1px solid ${tg.divider}`,
          bgcolor: tg.surface,
        }}
      >
        <IconButton size="small" onClick={onClose} aria-label="Закрыть поиск">
          <BackIcon />
        </IconButton>
        <TextField
          autoFocus
          fullWidth
          size="small"
          placeholder="Поиск по сообщениям (целые слова)"
          aria-label="Поиск по сообщениям"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          InputProps={{
            startAdornment: <SearchIcon fontSize="small" sx={{ mr: 1, color: tg.secondaryText }} />,
          }}
          sx={{
            '& .MuiOutlinedInput-root': { bgcolor: tg.searchBg, borderRadius: 3, '& fieldset': { border: 'none' } },
          }}
        />
      </Box>

      <Box sx={{ flex: 1, overflowY: 'auto', px: 1, py: 1 }}>
        {debounced.length < 2 && (
          <Typography sx={{ fontSize: '0.8rem', color: tg.secondaryText, textAlign: 'center', py: 3 }}>
            Введите минимум 2 символа. Поиск идёт по целым словам.
          </Typography>
        )}

        {debounced.length >= 2 && !isFetching && results.length === 0 && (
          <Typography sx={{ fontSize: '0.82rem', color: tg.secondaryText, textAlign: 'center', py: 3 }}>
            Ничего не найдено
          </Typography>
        )}

        {results.map((message) => (
          <Box
            key={message.id}
            onClick={() => onOpenResult(message)}
            sx={{
              px: 1,
              py: 0.75,
              mb: 0.5,
              borderRadius: 1,
              bgcolor: tg.surface,
              cursor: 'pointer',
              '&:hover': { bgcolor: tg.hover },
            }}
          >
            <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 1 }}>
              <Typography sx={{ fontSize: '0.72rem', color: tg.accent }}>
                {chatNames.get(message.chat_id) ?? 'Чат'}
              </Typography>
              <Typography sx={{ fontSize: '0.68rem', color: tg.secondaryText }}>
                {format(new Date(message.created_at), 'dd.MM HH:mm', { locale: ru })}
              </Typography>
            </Box>
            <Typography noWrap sx={{ fontSize: '0.85rem', color: tg.text }}>
              {message.body}
            </Typography>
          </Box>
        ))}
      </Box>
    </Box>
  )
}
