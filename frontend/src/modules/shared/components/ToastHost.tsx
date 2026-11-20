import { Box, IconButton, Paper, Typography } from '@mui/material'
import { Chat as ChatIcon, Close as CloseIcon } from '@mui/icons-material'
import { useToastStore } from '@/modules/shared/store/toastStore'
import { requestOpenChat } from '@/modules/chat/chatEvents'
import { tg } from '@/modules/chat/theme'

/**
 * Floating stack of incoming-message notifications. Click opens the chat.
 */
export default function ToastHost() {
  const toasts = useToastStore((state) => state.toasts)
  const dismiss = useToastStore((state) => state.dismiss)

  if (toasts.length === 0) return null

  return (
    <Box
      sx={{
        position: 'fixed',
        left: 16,
        bottom: 16,
        zIndex: 2000,
        display: 'flex',
        flexDirection: 'column',
        gap: 1,
        maxWidth: 'calc(100vw - 32px)',
      }}
    >
      {toasts.map((toast) => (
        <Paper
          key={toast.id}
          role="status"
          aria-live="polite"
          elevation={6}
          onClick={() => {
            requestOpenChat(toast.chatId)
            dismiss(toast.id)
          }}
          sx={{
            display: 'flex',
            alignItems: 'flex-start',
            gap: 1,
            p: 1.25,
            width: 340,
            maxWidth: '100%',
            borderRadius: 2,
            bgcolor: tg.surface,
            border: `1px solid ${tg.divider}`,
            cursor: 'pointer',
            transition: 'transform 120ms ease',
            '&:hover': { transform: 'translateY(-1px)' },
          }}
        >
          <ChatIcon sx={{ color: tg.accent, mt: 0.25, flexShrink: 0 }} />
          <Box sx={{ flex: 1, minWidth: 0 }}>
            <Typography noWrap sx={{ fontSize: '0.9rem', fontWeight: 700, color: tg.text }}>
              {toast.title}
            </Typography>
            <Typography
              sx={{
                fontSize: '0.85rem',
                color: tg.secondaryText,
                display: '-webkit-box',
                WebkitLineClamp: 2,
                WebkitBoxOrient: 'vertical',
                overflow: 'hidden',
                wordBreak: 'break-word',
              }}
            >
              {toast.body}
            </Typography>
          </Box>
          <IconButton
            size="small"
            aria-label="Закрыть уведомление"
            onClick={(event) => {
              event.stopPropagation()
              dismiss(toast.id)
            }}
            sx={{ mt: -0.5, mr: -0.5 }}
          >
            <CloseIcon fontSize="small" />
          </IconButton>
        </Paper>
      ))}
    </Box>
  )
}
