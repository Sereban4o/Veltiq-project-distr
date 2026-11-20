import { useEffect, useMemo, useState } from 'react'
import {
  Box,
  Checkbox,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Button,
  TextField,
  Typography,
} from '@mui/material'
import apiClient from '@/modules/shared/api/apiClient'
import { getFullName } from '../utils'
import { tg } from '../theme'
import type { Chat, Message } from '../types'

interface ForwardDialogProps {
  open: boolean
  message: Message | null
  chats: Chat[]
  currentUserId?: number
  onClose: () => void
  onDone: () => void
}

const MAX_TARGETS = 5

function chatTitle(chat: Chat, currentUserId?: number): string {
  if (chat.name) return chat.name
  if (chat.type === 'dm') {
    const other = chat.members?.find((member) => member.id !== currentUserId)
    return getFullName(other?.first_name, other?.last_name) || 'Личный чат'
  }
  return 'Чат'
}

export default function ForwardDialog({ open, message, chats, currentUserId, onClose, onDone }: ForwardDialogProps) {
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [query, setQuery] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (open) {
      setSelected(new Set())
      setQuery('')
      setError('')
    }
  }, [open])

  const visible = useMemo(() => {
    const q = query.trim().toLowerCase()
    return chats.filter((chat) => (q ? chatTitle(chat, currentUserId).toLowerCase().includes(q) : true))
  }, [chats, query, currentUserId])

  const toggle = (chatId: number) => {
    setSelected((previous) => {
      const next = new Set(previous)
      if (next.has(chatId)) {
        next.delete(chatId)
      } else if (next.size < MAX_TARGETS) {
        next.add(chatId)
      }
      return next
    })
  }

  const submit = async () => {
    if (!message || selected.size === 0 || busy) return
    setBusy(true)
    setError('')
    try {
      await apiClient.post(`/v1/chat/${message.chat_id}/messages/${message.id}/forward`, {
        chat_ids: Array.from(selected),
      })
      onDone()
      onClose()
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Не удалось переслать сообщение')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="xs">
      <DialogTitle>Переслать сообщение</DialogTitle>
      <DialogContent>
        {error && <Typography sx={{ fontSize: '0.8rem', color: 'error.main', mb: 1 }}>{error}</Typography>}
        <TextField
          fullWidth
          size="small"
          placeholder="Поиск чата..."
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          sx={{ mb: 1 }}
        />
        <Box sx={{ maxHeight: 320, overflowY: 'auto' }}>
          {visible.map((chat) => {
            const checked = selected.has(chat.id)
            return (
              <Box
                key={chat.id}
                onClick={() => toggle(chat.id)}
                sx={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 1,
                  px: 1,
                  py: 0.5,
                  borderRadius: 1,
                  cursor: 'pointer',
                  '&:hover': { bgcolor: tg.hover },
                }}
              >
                <Checkbox checked={checked} size="small" />
                <Typography sx={{ fontSize: '0.9rem' }} noWrap>
                  {chatTitle(chat, currentUserId)}
                </Typography>
              </Box>
            )
          })}
          {visible.length === 0 && (
            <Typography sx={{ fontSize: '0.82rem', color: tg.secondaryText, textAlign: 'center', py: 2 }}>
              Чаты не найдены
            </Typography>
          )}
        </Box>
        <Typography sx={{ fontSize: '0.72rem', color: tg.secondaryText, mt: 1 }}>
          Выбрано {selected.size} из {MAX_TARGETS}
        </Typography>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} sx={{ textTransform: 'none' }}>
          Отмена
        </Button>
        <Button
          variant="contained"
          disabled={busy || selected.size === 0}
          onClick={() => void submit()}
          sx={{ textTransform: 'none' }}
        >
          Переслать
        </Button>
      </DialogActions>
    </Dialog>
  )
}
