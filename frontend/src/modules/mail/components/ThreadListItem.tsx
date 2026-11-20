import { Box, Checkbox, Chip, ListItemButton, ListItemText, Typography } from '@mui/material'
import type { MailThreadSummary } from '../types'

interface ThreadListItemProps {
  summary: MailThreadSummary
  selected: boolean
  onClick: () => void
  bulkMode?: boolean
  checked?: boolean
  onToggleChecked?: () => void
}

function formatDate(value: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export default function ThreadListItem({
  summary,
  selected,
  onClick,
  bulkMode,
  checked,
  onToggleChecked,
}: ThreadListItemProps) {
  const latest = summary.latest
  const unread = summary.unread_count > 0
  const sender = latest.from_name || latest.from_email || '(без отправителя)'
  const bold = unread || !latest.is_read

  return (
    <ListItemButton
      selected={selected}
      onClick={onClick}
      alignItems="flex-start"
      sx={{ borderBottom: '1px solid', borderColor: 'divider' }}
    >
      {bulkMode && (
        <Checkbox
          size="small"
          checked={!!checked}
          onClick={(e) => e.stopPropagation()}
          onChange={() => onToggleChecked?.()}
          sx={{ mt: -0.5, ml: -1, mr: 0.5 }}
        />
      )}
      <ListItemText
        primary={
          <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 1 }}>
            <Typography noWrap sx={{ fontSize: '0.88rem', fontWeight: bold ? 700 : 400, flex: 1, minWidth: 0 }}>
              {sender}
            </Typography>
            <Typography sx={{ fontSize: '0.7rem', color: 'text.secondary', flexShrink: 0 }}>
              {formatDate(summary.last_at)}
            </Typography>
          </Box>
        }
        secondary={
          <Box component="span">
            <Box component="span" sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
              <Typography component="span" noWrap sx={{ fontSize: '0.82rem', fontWeight: bold ? 600 : 400 }}>
                {latest.subject || '(без темы)'}
              </Typography>
              {summary.count > 1 && (
                <Chip size="small" label={summary.count} sx={{ height: 18, fontSize: '0.68rem' }} />
              )}
              {summary.has_attachments ? (
                <Box component="span" sx={{ fontSize: '0.8rem' }}>
                  📎
                </Box>
              ) : null}
            </Box>
            <Typography
              component="span"
              noWrap
              sx={{ fontSize: '0.78rem', color: unread ? 'primary.main' : 'text.secondary', display: 'block' }}
            >
              {latest.preview || ''}
            </Typography>
          </Box>
        }
      />
      {unread && summary.count > 1 && (
        <Chip
          size="small"
          color="primary"
          label={summary.unread_count}
          sx={{ height: 18, fontSize: '0.68rem', ml: 0.5 }}
        />
      )}
    </ListItemButton>
  )
}
