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
  TextField,
  List,
  ListItemButton,
  IconButton,
  Chip,
  CircularProgress,
  Divider,
} from '@mui/material'
import { Close as CloseIcon, Delete as DeleteIcon, Add as AddIcon } from '@mui/icons-material'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import knowledgebaseApi from '../api/knowledgebaseApi'
import type { PageTemplate } from '@/types'

interface TemplatesDialogProps {
  open: boolean
  spaceKey: string
  canEdit: boolean
  onClose: () => void
}

const EMPTY_FORM = { name: '', description: '', body: '' }

export default function TemplatesDialog({ open, spaceKey, canEdit, onClose }: TemplatesDialogProps) {
  const queryClient = useQueryClient()
  const [editingId, setEditingId] = useState<number | null>(null)
  const [form, setForm] = useState(EMPTY_FORM)

  const { data: templates, isLoading } = useQuery<PageTemplate[]>({
    queryKey: ['kb-templates', spaceKey],
    queryFn: () => knowledgebaseApi.listTemplates(spaceKey),
    enabled: open,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['kb-templates', spaceKey] })

  const saveMutation = useMutation({
    mutationFn: () =>
      editingId ? knowledgebaseApi.updateTemplate(editingId, form) : knowledgebaseApi.createTemplate(spaceKey, form),
    onSuccess: () => {
      setForm(EMPTY_FORM)
      setEditingId(null)
      invalidate()
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => knowledgebaseApi.deleteTemplate(id),
    onSuccess: invalidate,
  })

  const startEdit = (template: PageTemplate) => {
    setEditingId(template.id)
    setForm({
      name: template.name,
      description: template.description || '',
      body: template.body || '',
    })
  }

  const resetForm = () => {
    setEditingId(null)
    setForm(EMPTY_FORM)
  }

  return (
    <Dialog open={open} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        Шаблоны страниц
        <IconButton onClick={onClose} size="small">
          <CloseIcon />
        </IconButton>
      </DialogTitle>
      <DialogContent dividers>
        {isLoading ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', py: 3 }}>
            <CircularProgress />
          </Box>
        ) : (
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
            <Box sx={{ width: { xs: '100%', md: 280 }, flexShrink: 0 }}>
              <List disablePadding sx={{ maxHeight: 420, overflow: 'auto' }}>
                {templates?.map((template) => (
                  <ListItemButton
                    key={template.id}
                    selected={editingId === template.id}
                    onClick={() => startEdit(template)}
                    sx={{ borderRadius: 1, mb: 0.5 }}
                  >
                    <Box sx={{ flexGrow: 1 }}>
                      <Stack direction="row" spacing={1} alignItems="center">
                        <Typography variant="body2">{template.name}</Typography>
                        {template.space_id === null && <Chip label="общий" size="small" />}
                      </Stack>
                      {template.description && (
                        <Typography variant="caption" color="text.secondary" display="block">
                          {template.description}
                        </Typography>
                      )}
                    </Box>
                    {canEdit && template.space_id !== null && (
                      <IconButton
                        size="small"
                        color="error"
                        onClick={(e) => {
                          e.stopPropagation()
                          deleteMutation.mutate(template.id)
                        }}
                      >
                        <DeleteIcon fontSize="small" />
                      </IconButton>
                    )}
                  </ListItemButton>
                ))}
              </List>
            </Box>

            <Divider orientation="vertical" flexItem sx={{ display: { xs: 'none', md: 'block' } }} />

            <Box sx={{ flexGrow: 1, minWidth: 0 }}>
              {canEdit ? (
                <Stack spacing={1.5}>
                  <Stack direction="row" alignItems="center" justifyContent="space-between">
                    <Typography variant="subtitle1">{editingId ? 'Редактировать шаблон' : 'Новый шаблон'}</Typography>
                    {editingId && (
                      <Button size="small" startIcon={<AddIcon />} onClick={resetForm}>
                        Новый
                      </Button>
                    )}
                  </Stack>
                  <TextField
                    size="small"
                    label="Название"
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                  />
                  <TextField
                    size="small"
                    label="Описание"
                    value={form.description}
                    onChange={(e) => setForm({ ...form, description: e.target.value })}
                  />
                  <TextField
                    multiline
                    minRows={8}
                    label="Содержимое (HTML)"
                    value={form.body}
                    onChange={(e) => setForm({ ...form, body: e.target.value })}
                    slotProps={{ input: { sx: { fontFamily: 'monospace', fontSize: '0.8rem' } } }}
                  />
                </Stack>
              ) : (
                <Typography variant="body2" color="text.secondary">
                  Только участники с правом редактирования могут управлять шаблонами.
                </Typography>
              )}
            </Box>
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Закрыть</Button>
        {canEdit && (
          <Button
            variant="contained"
            disabled={!form.name.trim() || saveMutation.isPending}
            onClick={() => saveMutation.mutate()}
          >
            {editingId ? 'Сохранить' : 'Создать'}
          </Button>
        )}
      </DialogActions>
    </Dialog>
  )
}
