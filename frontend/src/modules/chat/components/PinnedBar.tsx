import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Box, IconButton, Tooltip, Typography } from '@mui/material'
import { Close as CloseIcon, PushPin as PinIcon } from '@mui/icons-material'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapList } from '@/modules/shared/utils/apiResponse'
import { tg } from '../theme'
import type { PinnedMessage } from '../types'

interface PinnedBarProps {
  chatId: number
  canManage: boolean
  onOpenMessage: (messageId: number) => void
}

/** Compact strip of pinned messages above the conversation. */
export default function PinnedBar({ chatId, canManage, onOpenMessage }: PinnedBarProps) {
  const queryClient = useQueryClient()

  const { data: pins = [] } = useQuery<PinnedMessage[]>({
    queryKey: ['chat-pins', chatId],
    queryFn: async () => unwrapList<PinnedMessage>(await apiClient.get(`/v1/chat/${chatId}/pins`)),
    staleTime: 15_000,
  })

  if (pins.length === 0) return null

  const unpin = async (pin: PinnedMessage) => {
    try {
      await apiClient.delete(`/v1/chat/${chatId}/messages/${pin.message_id}/pin`)
      queryClient.invalidateQueries({ queryKey: ['chat-pins', chatId] })
    } catch (error) {
      console.error('Failed to unpin message', error)
    }
  }

  return (
    <Box
      sx={{
        display: 'flex',
        flexDirection: 'column',
        gap: 0.25,
        px: 1,
        py: 0.5,
        bgcolor: tg.surface,
        borderBottom: `1px solid ${tg.divider}`,
        maxHeight: 108,
        overflowY: 'auto',
      }}
    >
      {pins.map((pin) => {
        const message = pin.message
        const author = [message.sender?.first_name, message.sender?.last_name].filter(Boolean).join(' ')
        const text =
          message.body?.trim() ||
          (message.type === 'image' ? '📷 Изображение' : message.type === 'file' ? '📎 Файл' : 'Сообщение')

        return (
          <Box
            key={pin.id}
            sx={{
              display: 'flex',
              alignItems: 'center',
              gap: 0.75,
              borderRadius: 1,
              px: 0.75,
              py: 0.25,
              cursor: 'pointer',
              '&:hover': { bgcolor: tg.hover },
            }}
            onClick={() => onOpenMessage(message.id)}
          >
            <PinIcon sx={{ fontSize: 14, color: tg.accent, flexShrink: 0 }} />
            <Box sx={{ minWidth: 0, flex: 1 }}>
              <Typography noWrap sx={{ fontSize: '0.68rem', color: tg.accent, lineHeight: 1.2 }}>
                {author || 'Закреплённое сообщение'}
              </Typography>
              <Typography noWrap sx={{ fontSize: '0.78rem', color: tg.text, lineHeight: 1.3 }}>
                {text}
              </Typography>
            </Box>
            {canManage && (
              <Tooltip title="Открепить">
                <IconButton
                  size="small"
                  aria-label="Открепить сообщение"
                  onClick={(event) => {
                    event.stopPropagation()
                    void unpin(pin)
                  }}
                >
                  <CloseIcon sx={{ fontSize: 14 }} />
                </IconButton>
              </Tooltip>
            )}
          </Box>
        )
      })}
    </Box>
  )
}
