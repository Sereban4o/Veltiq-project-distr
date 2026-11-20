import { useState } from 'react'
import { Box, Button, Divider, IconButton, Menu, MenuItem, Tooltip, Typography } from '@mui/material'
import {
  Close as CloseIcon,
  Delete as DeleteIcon,
  DriveFileMove as MoveIcon,
  MarkEmailRead as MarkReadIcon,
  MarkEmailUnread as MarkUnreadIcon,
  StarBorder as StarIcon,
} from '@mui/icons-material'
import type { MailFolder, MailFolderRecord } from '../types'
import { formatCount } from '../utils/format'

interface BulkActionBarProps {
  count: number
  folders: MailFolderRecord[]
  systemFolders: { value: MailFolder; label: string }[]
  onRead: () => void
  onUnread: () => void
  onStar: () => void
  onMove: (target: { folderId: number } | { folder: MailFolder }) => void
  onDelete: () => void
  onClear: () => void
  busy?: boolean
}

export default function BulkActionBar({
  count,
  folders,
  systemFolders,
  onRead,
  onUnread,
  onStar,
  onMove,
  onDelete,
  onClear,
  busy,
}: BulkActionBarProps) {
  const [anchor, setAnchor] = useState<null | HTMLElement>(null)

  return (
    <Box
      sx={{
        display: 'flex',
        alignItems: 'center',
        gap: 0.5,
        px: 1.5,
        py: 0.75,
        bgcolor: 'action.hover',
        borderBottom: '1px solid',
        borderColor: 'divider',
      }}
    >
      <Typography sx={{ fontSize: '0.8rem', fontWeight: 600, mr: 1 }}>Выбрано: {formatCount(count)}</Typography>
      <Tooltip title="Прочитано">
        <span>
          <IconButton size="small" disabled={busy || count === 0} onClick={onRead}>
            <MarkReadIcon fontSize="small" />
          </IconButton>
        </span>
      </Tooltip>
      <Tooltip title="Не прочитано">
        <span>
          <IconButton size="small" disabled={busy || count === 0} onClick={onUnread}>
            <MarkUnreadIcon fontSize="small" />
          </IconButton>
        </span>
      </Tooltip>
      <Tooltip title="В избранное">
        <span>
          <IconButton size="small" disabled={busy || count === 0} onClick={onStar}>
            <StarIcon fontSize="small" />
          </IconButton>
        </span>
      </Tooltip>
      <Tooltip title="Переместить">
        <span>
          <IconButton size="small" disabled={busy || count === 0} onClick={(e) => setAnchor(e.currentTarget)}>
            <MoveIcon fontSize="small" />
          </IconButton>
        </span>
      </Tooltip>
      <Tooltip title="Удалить">
        <span>
          <IconButton size="small" disabled={busy || count === 0} onClick={onDelete}>
            <DeleteIcon fontSize="small" />
          </IconButton>
        </span>
      </Tooltip>

      <Box sx={{ flex: 1 }} />
      <Button size="small" startIcon={<CloseIcon />} onClick={onClear} sx={{ textTransform: 'none' }}>
        Отмена
      </Button>

      <Menu anchorEl={anchor} open={Boolean(anchor)} onClose={() => setAnchor(null)}>
        {folders.map((folder) => (
          <MenuItem
            key={`bulk-custom-${folder.id}`}
            onClick={() => {
              setAnchor(null)
              onMove({ folderId: folder.id })
            }}
          >
            {folder.name}
          </MenuItem>
        ))}
        {folders.length > 0 && <Divider />}
        {systemFolders.map((folder) => (
          <MenuItem
            key={`bulk-${folder.value}`}
            onClick={() => {
              setAnchor(null)
              onMove({ folder: folder.value })
            }}
          >
            {folder.label}
          </MenuItem>
        ))}
      </Menu>
    </Box>
  )
}
