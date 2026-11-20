import { useEffect, useState } from 'react'
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Box,
  Stack,
  TextField,
  MenuItem,
  IconButton,
} from '@mui/material'
import { Close as CloseIcon } from '@mui/icons-material'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import knowledgebaseApi from '../api/knowledgebaseApi'
import RichTextEditor from '@/modules/shared/components/RichTextEditor'
import type { Page, PageTemplate } from '@/types'

interface CreatePageDialogProps {
  open: boolean
  spaceKey: string
  parentId?: number | null
  onClose: () => void
  onCreated?: (page: Page) => void
}

export default function CreatePageDialog({
  open,
  spaceKey,
  parentId = null,
  onClose,
  onCreated,
}: CreatePageDialogProps) {
  const queryClient = useQueryClient()
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [templateId, setTemplateId] = useState<number | ''>('')

  const { data: templates } = useQuery<PageTemplate[]>({
    queryKey: ['kb-templates', spaceKey],
    queryFn: () => knowledgebaseApi.listTemplates(spaceKey),
    enabled: open,
  })

  useEffect(() => {
    if (open) {
      setTitle('')
      setBody('')
      setTemplateId('')
    }
  }, [open])

  const createMutation = useMutation({
    mutationFn: () =>
      knowledgebaseApi.createPage(spaceKey, {
        title: title.trim(),
        body,
        parent_id: parentId,
        template_id: templateId === '' ? null : templateId,
      }),
    onSuccess: (page) => {
      queryClient.invalidateQueries({ queryKey: ['kb-tree', spaceKey] })
      onCreated?.(page)
      onClose()
    },
  })

  const handleTemplateChange = (value: number | '') => {
    setTemplateId(value)
    const template = (templates || []).find((t) => t.id === value)
    if (template) {
      if (!title) setTitle(template.name)
      setBody(template.body || '')
    }
  }

  return (
    <Dialog open={open} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        {parentId ? 'Новая дочерняя страница' : 'Новая страница'}
        <IconButton onClick={onClose} size="small">
          <CloseIcon />
        </IconButton>
      </DialogTitle>
      <DialogContent dividers>
        <Stack spacing={2} sx={{ pt: 1 }}>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField
              label="Название"
              size="small"
              fullWidth
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              autoFocus
            />
            <TextField
              select
              label="Шаблон"
              size="small"
              sx={{ minWidth: 200 }}
              value={templateId}
              onChange={(e) => handleTemplateChange(e.target.value === '' ? '' : Number(e.target.value))}
            >
              <MenuItem value="">Пустая страница</MenuItem>
              {(templates || []).map((template) => (
                <MenuItem key={template.id} value={template.id}>
                  {template.name}
                  {template.space_id === null ? ' (общий)' : ''}
                </MenuItem>
              ))}
            </TextField>
          </Stack>

          <Box>
            <RichTextEditor
              content={body}
              onChange={setBody}
              onUploadImage={knowledgebaseApi.uploadImage}
              placeholder="Начните писать..."
              minHeight={260}
            />
          </Box>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Отмена</Button>
        <Button
          variant="contained"
          disabled={!title.trim() || createMutation.isPending}
          onClick={() => createMutation.mutate()}
        >
          Создать
        </Button>
      </DialogActions>
    </Dialog>
  )
}
