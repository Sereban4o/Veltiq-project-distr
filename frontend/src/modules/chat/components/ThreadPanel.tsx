import { useMemo, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Box, Button, IconButton, TextField, Typography } from '@mui/material'
import { ArrowBack as BackIcon, Send as SendIcon } from '@mui/icons-material'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapList } from '@/modules/shared/utils/apiResponse'
import { useAuth } from '@/modules/auth/store/authStore'
import { formatMessageTime, getFullName, getUserAvatarColor, getInitials } from '../utils'
import { tg } from '../theme'
import type { Message } from '../types'

interface ThreadPanelProps {
  chatId: number
  root: Message
  onClose: () => void
}

/** Replies of a single message (one nesting level, like Telegram threads). */
export default function ThreadPanel({ chatId, root, onClose }: ThreadPanelProps) {
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)

  const { data: replies = [], isLoading } = useQuery<Message[]>({
    queryKey: ['chat-replies', chatId, root.id],
    queryFn: async () => {
      const res = await apiClient.get(`/v1/chat/${chatId}/messages/${root.id}/replies`, {
        params: { per_page: 50 },
      })
      return unwrapList<Message>(res).slice().reverse()
    },
    refetchInterval: 4_000,
  })

  const rootAuthor = useMemo(
    () => getFullName(root.sender?.first_name, root.sender?.last_name) || root.sender?.email || 'Автор',
    [root.sender],
  )

  const submit = async () => {
    const body = text.trim()
    if (!body || busy) return
    setBusy(true)
    try {
      await apiClient.post(`/v1/chat/${chatId}/messages`, { body, parent_id: root.id })
      setText('')
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['chat-replies', chatId, root.id] }),
        queryClient.invalidateQueries({ queryKey: ['chat-messages', chatId] }),
        queryClient.invalidateQueries({ queryKey: ['chat-my'] }),
      ])
    } catch (error) {
      console.error('Failed to send thread reply', error)
    } finally {
      setBusy(false)
    }
  }

  return (
    <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column', minHeight: 0 }}>
      <Box
        sx={{
          display: 'flex',
          alignItems: 'center',
          gap: 1,
          px: 1,
          py: 0.75,
          borderBottom: `1px solid ${tg.divider}`,
          bgcolor: tg.surface,
        }}
      >
        <IconButton size="small" onClick={onClose} aria-label="Назад к чату">
          <BackIcon />
        </IconButton>
        <Box sx={{ minWidth: 0 }}>
          <Typography sx={{ fontSize: '0.95rem', fontWeight: 600, color: tg.text }}>Тред</Typography>
          <Typography noWrap sx={{ fontSize: '0.72rem', color: tg.secondaryText }}>
            {replies.length} ответов
          </Typography>
        </Box>
      </Box>

      <Box sx={{ flex: 1, overflowY: 'auto', px: 1, py: 1, bgcolor: tg.chatBg }}>
        <Box
          sx={{
            p: 1,
            mb: 1,
            borderRadius: 2,
            bgcolor: tg.surface,
            border: `1px solid ${tg.divider}`,
          }}
        >
          <Typography sx={{ fontSize: '0.75rem', color: tg.accent, mb: 0.25 }}>{rootAuthor}</Typography>
          <Typography sx={{ fontSize: '0.88rem', color: tg.text, whiteSpace: 'pre-wrap' }}>{root.body}</Typography>
        </Box>

        {isLoading && replies.length === 0 && (
          <Typography sx={{ fontSize: '0.8rem', color: tg.secondaryText, textAlign: 'center', py: 2 }}>
            Загрузка...
          </Typography>
        )}

        {replies.map((reply) => {
          const name = getFullName(reply.sender?.first_name, reply.sender?.last_name) || 'Пользователь'
          const own = reply.sender_id === user?.id
          return (
            <Box
              key={reply.id}
              sx={{
                display: 'flex',
                justifyContent: own ? 'flex-end' : 'flex-start',
                mb: 0.5,
              }}
            >
              <Box
                sx={{
                  maxWidth: '85%',
                  px: 1.25,
                  py: 0.75,
                  borderRadius: 2,
                  bgcolor: own ? tg.outgoingBg : tg.surface,
                  color: tg.text,
                }}
              >
                <Typography sx={{ fontSize: '0.72rem', color: tg.accent }}>
                  {name}
                  <Box component="span" sx={{ color: getUserAvatarColor(reply.sender_id), ml: 0.5 }}>
                    {getInitials(reply.sender?.first_name, reply.sender?.last_name)}
                  </Box>
                </Typography>
                <Typography sx={{ fontSize: '0.88rem', whiteSpace: 'pre-wrap' }}>{reply.body}</Typography>
                <Typography sx={{ fontSize: '0.65rem', color: tg.secondaryText, textAlign: 'right' }}>
                  {formatMessageTime(reply.created_at)}
                </Typography>
              </Box>
            </Box>
          )
        })}
      </Box>

      <Box
        sx={{
          display: 'flex',
          alignItems: 'flex-end',
          gap: 0.5,
          px: 1,
          py: 0.75,
          borderTop: `1px solid ${tg.divider}`,
          bgcolor: tg.surface,
        }}
      >
        <TextField
          fullWidth
          multiline
          maxRows={4}
          size="small"
          placeholder="Ответ в тред..."
          aria-label="Ответ в тред"
          value={text}
          onChange={(event) => setText(event.target.value)}
          onKeyDown={(event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
              event.preventDefault()
              void submit()
            }
          }}
          sx={{
            '& .MuiOutlinedInput-root': { bgcolor: tg.searchBg, borderRadius: 5, '& fieldset': { border: 'none' } },
          }}
        />
        <IconButton
          onClick={() => void submit()}
          disabled={!text.trim() || busy}
          aria-label="Отправить ответ"
          sx={{ color: text.trim() ? tg.accent : tg.secondaryText }}
        >
          <SendIcon />
        </IconButton>
      </Box>
    </Box>
  )
}

/** Kept for callers that render the reply button as a `Button`. */
export function ThreadOpenButton({ count, onClick }: { count: number; onClick: () => void }) {
  return (
    <Button size="small" onClick={onClick} sx={{ textTransform: 'none', fontSize: '0.75rem' }}>
      {count} ответов
    </Button>
  )
}
