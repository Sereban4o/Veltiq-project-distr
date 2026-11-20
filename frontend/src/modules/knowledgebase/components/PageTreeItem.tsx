import { useState } from 'react'
import { Box, IconButton, ListItem, Menu, MenuItem, Tooltip, Typography, Chip } from '@mui/material'
import {
  ChevronRight as ChevronRightIcon,
  ExpandMore as ExpandMoreIcon,
  DragIndicator as DragIndicatorIcon,
  MoreVert as MoreVertIcon,
  Add as AddIcon,
} from '@mui/icons-material'
import type { PageNode } from '@/types'

interface PageTreeItemProps {
  node: PageNode
  depth: number
  indentationWidth: number
  selected: boolean
  expanded: boolean
  hasChildren: boolean
  onSelect: (id: number) => void
  onToggle: (id: number) => void
  onAddChild: (parentId: number) => void
  onRename?: (node: PageNode) => void
  onManagePermissions?: (node: PageNode) => void
  onExport?: (node: PageNode) => void
  onPublish?: (node: PageNode) => void
  onDelete?: (node: PageNode) => void
  dragHandleProps?: React.HTMLAttributes<HTMLElement>
  isDragging?: boolean
}

const STATUS_COLORS: Record<PageNode['status'], string> = {
  published: '#10b981',
  draft: '#f59e0b',
  archived: '#9ca3af',
}

export default function PageTreeItem({
  node,
  depth,
  indentationWidth,
  selected,
  expanded,
  hasChildren,
  onSelect,
  onToggle,
  onAddChild,
  onRename,
  onManagePermissions,
  onExport,
  onPublish,
  onDelete,
  dragHandleProps,
  isDragging,
}: PageTreeItemProps) {
  const [menuAnchor, setMenuAnchor] = useState<null | HTMLElement>(null)

  const closeMenu = () => setMenuAnchor(null)

  return (
    <ListItem
      disablePadding
      sx={{
        pl: 0.5,
        opacity: isDragging ? 0.4 : 1,
        borderRadius: 1,
        bgcolor: selected ? 'action.selected' : 'transparent',
      }}
      secondaryAction={undefined}
    >
      <Box
        sx={{
          display: 'flex',
          alignItems: 'center',
          width: '100%',
          pl: depth * indentationWidth,
          pr: 0.5,
          minHeight: 34,
          borderRadius: 1,
          cursor: 'pointer',
          '&:hover': { bgcolor: 'action.hover' },
          '&:hover .tree-actions': { opacity: 1 },
        }}
        onClick={() => onSelect(node.id)}
      >
        <Box
          {...dragHandleProps}
          className="tree-actions"
          sx={{ display: 'flex', alignItems: 'center', opacity: 0, cursor: 'grab', mr: 0.25 }}
          onClick={(e) => e.stopPropagation()}
        >
          <DragIndicatorIcon fontSize="small" sx={{ color: 'text.disabled' }} />
        </Box>

        {hasChildren ? (
          <IconButton
            size="small"
            sx={{ p: 0.25 }}
            onClick={(e) => {
              e.stopPropagation()
              onToggle(node.id)
            }}
          >
            {expanded ? <ExpandMoreIcon fontSize="small" /> : <ChevronRightIcon fontSize="small" />}
          </IconButton>
        ) : (
          <Box sx={{ width: 28 }} />
        )}

        <Box
          sx={{
            width: 8,
            height: 8,
            borderRadius: '50%',
            bgcolor: STATUS_COLORS[node.status],
            mr: 1,
            flexShrink: 0,
          }}
        />

        <Typography
          variant="body2"
          sx={{
            flexGrow: 1,
            overflow: 'hidden',
            textOverflow: 'ellipsis',
            whiteSpace: 'nowrap',
            fontWeight: selected ? 600 : 400,
          }}
        >
          {node.title}
        </Typography>

        {node.status !== 'published' && (
          <Chip label={node.status} size="small" sx={{ height: 18, fontSize: '0.65rem', mr: 0.5 }} />
        )}

        <Box className="tree-actions" sx={{ display: 'flex', opacity: 0 }} onClick={(e) => e.stopPropagation()}>
          {node.can_edit && (
            <Tooltip title="Subpage">
              <IconButton size="small" sx={{ p: 0.25 }} onClick={() => onAddChild(node.id)}>
                <AddIcon fontSize="small" />
              </IconButton>
            </Tooltip>
          )}
          <IconButton size="small" sx={{ p: 0.25 }} onClick={(e) => setMenuAnchor(e.currentTarget)}>
            <MoreVertIcon fontSize="small" />
          </IconButton>
        </Box>
      </Box>

      <Menu anchorEl={menuAnchor} open={Boolean(menuAnchor)} onClose={closeMenu}>
        {onRename && node.can_edit && (
          <MenuItem
            onClick={() => {
              closeMenu()
              onRename(node)
            }}
          >
            Переименовать
          </MenuItem>
        )}
        {onPublish && node.can_edit && node.status !== 'published' && (
          <MenuItem
            onClick={() => {
              closeMenu()
              onPublish(node)
            }}
          >
            Опубликовать
          </MenuItem>
        )}
        {onManagePermissions && node.can_edit && (
          <MenuItem
            onClick={() => {
              closeMenu()
              onManagePermissions(node)
            }}
          >
            Доступ
          </MenuItem>
        )}
        {onExport && (
          <MenuItem
            onClick={() => {
              closeMenu()
              onExport(node)
            }}
          >
            Экспорт
          </MenuItem>
        )}
        {onDelete && node.can_edit && (
          <MenuItem
            sx={{ color: 'error.main' }}
            onClick={() => {
              closeMenu()
              onDelete(node)
            }}
          >
            Удалить
          </MenuItem>
        )}
      </Menu>
    </ListItem>
  )
}
