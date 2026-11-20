import { useState } from 'react'
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Box,
  Stack,
  Typography,
  List,
  ListItemButton,
  Chip,
  IconButton,
  CircularProgress,
} from '@mui/material'
import { Close as CloseIcon, Restore as RestoreIcon } from '@mui/icons-material'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import knowledgebaseApi from '../api/knowledgebaseApi'
import DiffView from './DiffView'
import type { PageVersion } from '@/types'

interface VersionsDialogProps {
  open: boolean
  onClose: () => void
  pageId: number
  canEdit: boolean
  onRestored?: () => void
}

export default function VersionsDialog({ open, onClose, pageId, canEdit, onRestored }: VersionsDialogProps) {
  const queryClient = useQueryClient()
  const [selected, setSelected] = useState<number[]>([])

  const { data: versions, isLoading } = useQuery<PageVersion[]>({
    queryKey: ['page-versions', pageId],
    queryFn: () => knowledgebaseApi.getVersions(pageId),
    enabled: open && !!pageId,
  })

  const compareFrom = selected[0]
  const compareTo = selected[1]

  const { data: diff, isLoading: diffLoading } = useQuery({
    queryKey: ['page-diff', pageId, compareFrom, compareTo],
    queryFn: () => knowledgebaseApi.getDiff(pageId, compareFrom, compareTo),
    enabled: open && compareFrom != null && compareTo != null,
  })

  const restoreMutation = useMutation({
    mutationFn: (version: number) => knowledgebaseApi.restoreVersion(pageId, version),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['page', pageId] })
      queryClient.invalidateQueries({ queryKey: ['page-versions', pageId] })
      onRestored?.()
    },
  })

  const toggleVersion = (version: number) => {
    setSelected((prev) => {
      if (prev.includes(version)) return prev.filter((v) => v !== version)
      const next = [...prev, version]
      return next.slice(-2)
    })
  }

  return (
    <Dialog open={open} onClose={onClose} maxWidth="lg" fullWidth>
      <DialogTitle sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        История версий
        <IconButton onClick={onClose} size="small">
          <CloseIcon />
        </IconButton>
      </DialogTitle>
      <DialogContent dividers>
        {isLoading ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', py: 4 }}>
            <CircularProgress />
          </Box>
        ) : (
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
            <Box sx={{ width: { xs: '100%', md: 280 }, flexShrink: 0 }}>
              <Typography variant="caption" color="text.secondary">
                Выберите две версии для сравнения
              </Typography>
              <List disablePadding sx={{ maxHeight: 420, overflow: 'auto' }}>
                {versions?.map((version) => (
                  <ListItemButton
                    key={version.id}
                    selected={selected.includes(version.version)}
                    onClick={() => toggleVersion(version.version)}
                    sx={{ borderRadius: 1, mb: 0.5 }}
                  >
                    <Box sx={{ flexGrow: 1 }}>
                      <Stack direction="row" spacing={1} alignItems="center">
                        <Chip label={`v${version.version}`} size="small" />
                        <Typography variant="body2">{version.author?.full_name || 'Неизвестно'}</Typography>
                      </Stack>
                      <Typography variant="caption" color="text.secondary" display="block">
                        {new Date(version.created_at).toLocaleString()}
                      </Typography>
                      {version.summary && (
                        <Typography variant="caption" color="text.secondary" display="block">
                          {version.summary}
                        </Typography>
                      )}
                    </Box>
                    {canEdit && (
                      <IconButton
                        size="small"
                        title="Восстановить"
                        onClick={(e) => {
                          e.stopPropagation()
                          restoreMutation.mutate(version.version)
                        }}
                      >
                        <RestoreIcon fontSize="small" />
                      </IconButton>
                    )}
                  </ListItemButton>
                ))}
              </List>
            </Box>

            <Box sx={{ flexGrow: 1, minWidth: 0 }}>
              {compareFrom == null || compareTo == null ? (
                <Typography variant="body2" color="text.secondary" sx={{ py: 4 }}>
                  Выберите две версии, чтобы увидеть различия
                </Typography>
              ) : diffLoading ? (
                <Box sx={{ display: 'flex', justifyContent: 'center', py: 4 }}>
                  <CircularProgress />
                </Box>
              ) : diff ? (
                <DiffView diff={diff.diff} />
              ) : null}
            </Box>
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Закрыть</Button>
      </DialogActions>
    </Dialog>
  )
}
