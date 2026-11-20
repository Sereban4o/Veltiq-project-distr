import { useEffect, useState } from 'react'
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, TextField } from '@mui/material'
import apiClient from '@/modules/shared/api/apiClient'
import type { MailFolderRecord } from '../types'

interface MailFolderDialogProps {
  open: boolean
  folder?: MailFolderRecord | null
  onClose: () => void
  onSaved: () => void
}

export default function MailFolderDialog({ open, folder, onClose, onSaved }: MailFolderDialogProps) {
  const [name, setName] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!open) return
    setName(folder?.name || '')
    setError('')
  }, [open, folder])

  const handleSave = async () => {
    const trimmed = name.trim()
    if (!trimmed) {
      setError('Укажите название папки')
      return
    }
    setBusy(true)
    setError('')
    try {
      if (folder?.id) {
        await apiClient.patch(`/v1/mail/folders/${folder.id}`, { name: trimmed })
      } else {
        await apiClient.post('/v1/mail/folders', { name: trimmed })
      }
      onSaved()
      onClose()
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Не удалось сохранить папку')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="xs">
      <DialogTitle>{folder?.id ? 'Переименовать папку' : 'Новая папка'}</DialogTitle>
      <DialogContent>
        {error && (
          <Alert severity="error" sx={{ mb: 2 }}>
            {error}
          </Alert>
        )}
        <Box sx={{ mt: 1 }}>
          <TextField
            autoFocus
            fullWidth
            label="Название папки"
            value={name}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault()
                handleSave()
              }
            }}
          />
        </Box>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} sx={{ textTransform: 'none' }}>
          Отмена
        </Button>
        <Button variant="contained" onClick={handleSave} disabled={busy} sx={{ textTransform: 'none' }}>
          {busy ? 'Сохранение...' : 'Сохранить'}
        </Button>
      </DialogActions>
    </Dialog>
  )
}
