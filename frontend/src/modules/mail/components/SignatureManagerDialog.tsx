import { useEffect, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  FormControlLabel,
  IconButton,
  List,
  ListItem,
  ListItemText,
  Stack,
  Switch,
  TextField,
  Typography,
} from '@mui/material'
import {
  Delete as DeleteIcon,
  Edit as EditIcon,
  Star as StarIcon,
  StarBorder as StarBorderIcon,
} from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import RichTextEditor from '@/modules/shared/components/RichTextEditor'
import mailApi from '../api'
import type { MailSignature } from '../types'

interface SignatureManagerDialogProps {
  open: boolean
  onClose: () => void
}

export default function SignatureManagerDialog({ open, onClose }: SignatureManagerDialogProps) {
  const queryClient = useQueryClient()
  const [editing, setEditing] = useState<MailSignature | null>(null)
  const [name, setName] = useState('')
  const [bodyHtml, setBodyHtml] = useState('')
  const [isDefault, setIsDefault] = useState(false)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const { data: signatures = [] } = useQuery<MailSignature[]>({
    queryKey: ['mail-signatures'],
    queryFn: () => mailApi.listSignatures(),
    enabled: open,
  })

  const resetForm = () => {
    setEditing(null)
    setName('')
    setBodyHtml('')
    setIsDefault(false)
    setError('')
  }

  useEffect(() => {
    if (!open) resetForm()
  }, [open])

  const startEdit = (signature: MailSignature) => {
    setEditing(signature)
    setName(signature.name)
    setBodyHtml(signature.body_html)
    setIsDefault(signature.is_default)
    setError('')
  }

  const reload = () => {
    queryClient.invalidateQueries({ queryKey: ['mail-signatures'] })
  }

  const handleSave = async () => {
    setError('')
    if (!name.trim()) {
      setError('Укажите название подписи')
      return
    }
    setBusy(true)
    try {
      const payload = { name: name.trim(), body_html: bodyHtml, is_default: isDefault }
      if (editing) {
        await mailApi.updateSignature(editing.id, payload)
      } else {
        await mailApi.createSignature(payload)
      }
      resetForm()
      reload()
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Не удалось сохранить подпись')
    } finally {
      setBusy(false)
    }
  }

  const handleDelete = async (signature: MailSignature) => {
    if (!window.confirm(`Удалить подпись «${signature.name}»?`)) return
    try {
      await mailApi.deleteSignature(signature.id)
      if (editing?.id === signature.id) resetForm()
      reload()
    } catch {
      /* ignore */
    }
  }

  const handleMakeDefault = async (signature: MailSignature) => {
    try {
      await mailApi.updateSignature(signature.id, { is_default: true })
      reload()
    } catch {
      /* ignore */
    }
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle>Подписи</DialogTitle>
      <DialogContent>
        {signatures.length === 0 ? (
          <Typography color="text.secondary" sx={{ mb: 2 }}>
            Подписей пока нет
          </Typography>
        ) : (
          <List dense>
            {signatures.map((signature) => (
              <ListItem
                key={signature.id}
                secondaryAction={
                  <Stack direction="row" spacing={0.5}>
                    {signature.is_default ? (
                      <Chip size="small" label="По умолчанию" color="primary" />
                    ) : (
                      <IconButton
                        size="small"
                        title="Сделать по умолчанию"
                        onClick={() => handleMakeDefault(signature)}
                      >
                        <StarBorderIcon fontSize="small" />
                      </IconButton>
                    )}
                    <IconButton size="small" onClick={() => startEdit(signature)}>
                      <EditIcon fontSize="small" />
                    </IconButton>
                    <IconButton size="small" onClick={() => handleDelete(signature)}>
                      <DeleteIcon fontSize="small" />
                    </IconButton>
                  </Stack>
                }
              >
                <ListItemText
                  primary={signature.name}
                  secondary={signature.is_default ? 'Подставляется автоматически' : undefined}
                />
                {signature.is_default && <StarIcon sx={{ display: 'none' }} />}
              </ListItem>
            ))}
          </List>
        )}

        <Divider sx={{ my: 2 }} />

        <Typography sx={{ fontWeight: 600, mb: 1 }}>{editing ? 'Редактировать подпись' : 'Новая подпись'}</Typography>

        {error && (
          <Alert severity="error" sx={{ mb: 2 }}>
            {error}
          </Alert>
        )}

        <Stack spacing={2}>
          <TextField label="Название" size="small" value={name} onChange={(e) => setName(e.target.value)} />
          <RichTextEditor content={bodyHtml} onChange={setBodyHtml} placeholder="Текст подписи" minHeight={140} />
          <FormControlLabel
            control={<Switch checked={isDefault} onChange={(e) => setIsDefault(e.target.checked)} />}
            label="Использовать по умолчанию"
          />
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
