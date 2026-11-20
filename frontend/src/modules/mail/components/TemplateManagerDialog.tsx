import { useEffect, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  IconButton,
  List,
  ListItem,
  ListItemText,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import { Delete as DeleteIcon, Edit as EditIcon } from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import RichTextEditor from '@/modules/shared/components/RichTextEditor'
import mailApi from '../api'
import type { MailTemplate } from '../types'

interface TemplateManagerDialogProps {
  open: boolean
  onClose: () => void
}

export default function TemplateManagerDialog({ open, onClose }: TemplateManagerDialogProps) {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<MailTemplate | null>(null)
  const [name, setName] = useState('')
  const [subject, setSubject] = useState('')
  const [bodyHtml, setBodyHtml] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const { data: templates = [] } = useQuery<MailTemplate[]>({
    queryKey: ['mail-templates'],
    queryFn: () => mailApi.listTemplates(),
    enabled: open,
  })

  const resetForm = () => {
    setEditing(null)
    setName('')
    setSubject('')
    setBodyHtml('')
    setError('')
  }

  useEffect(() => {
    if (!open) resetForm()
  }, [open])

  const startEdit = (template: MailTemplate) => {
    setEditing(template)
    setName(template.name)
    setSubject(template.subject ?? '')
    setBodyHtml(template.body_html)
    setError('')
  }

  const reload = () => {
    queryClient.invalidateQueries({ queryKey: ['mail-templates'] })
  }

  const handleSave = async () => {
    setError('')
    if (!name.trim()) {
      setError('Укажите название шаблона')
      return
    }
    setBusy(true)
    try {
      const payload = { name: name.trim(), subject: subject.trim() || null, body_html: bodyHtml }
      if (editing) {
        await mailApi.updateTemplate(editing.id, payload)
      } else {
        await mailApi.createTemplate(payload)
      }
      resetForm()
      reload()
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Не удалось сохранить шаблон')
    } finally {
      setBusy(false)
    }
  }

  const handleDelete = async (template: MailTemplate) => {
    if (!window.confirm(`Удалить шаблон «${template.name}»?`)) return
    try {
      await mailApi.deleteTemplate(template.id)
      if (editing?.id === template.id) resetForm()
      reload()
    } catch {
      /* ignore */
    }
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle>Шаблоны</DialogTitle>
      <DialogContent>
        {templates.length === 0 ? (
          <Typography color="text.secondary" sx={{ mb: 2 }}>
            Шаблонов пока нет
          </Typography>
        ) : (
          <List dense>
            {templates.map((template) => (
              <ListItem
                key={template.id}
                secondaryAction={
                  <Stack direction="row" spacing={0.5}>
                    <IconButton size="small" onClick={() => startEdit(template)}>
                      <EditIcon fontSize="small" />
                    </IconButton>
                    <IconButton size="small" onClick={() => handleDelete(template)}>
                      <DeleteIcon fontSize="small" />
                    </IconButton>
                  </Stack>
                }
              >
                <ListItemText primary={template.name} secondary={template.subject || 'Без темы'} />
              </ListItem>
            ))}
          </List>
        )}

        <Divider sx={{ my: 2 }} />

        <Typography sx={{ fontWeight: 600, mb: 1 }}>{editing ? 'Редактировать шаблон' : 'Новый шаблон'}</Typography>

        <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
          Переменные: {'{{to_name}}'}, {'{{my_name}}'}, {'{{date}}'}
        </Typography>

        {error && (
          <Alert severity="error" sx={{ mb: 2 }}>
            {error}
          </Alert>
        )}

        <Stack spacing={2}>
          <TextField label="Название" size="small" value={name} onChange={(e) => setName(e.target.value)} />
          <TextField label="Тема" size="small" value={subject} onChange={(e) => setSubject(e.target.value)} />
          <RichTextEditor content={bodyHtml} onChange={setBodyHtml} placeholder="Текст шаблона" minHeight={160} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Box sx={{ flex: 1 }} />
        {editing && <Button onClick={resetForm}>Отмена</Button>}
        <Button variant="contained" onClick={handleSave} disabled={busy}>
          {editing ? 'Сохранить' : 'Добавить'}
        </Button>
      </DialogActions>
    </Dialog>
  )
}
