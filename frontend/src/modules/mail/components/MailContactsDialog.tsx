import { useState } from 'react'
import {
  Alert,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  List,
  ListItem,
  ListItemText,
  Stack,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import { Delete as DeleteIcon, Edit as EditIcon } from '@mui/icons-material'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import type { MailContact } from '../types'

interface MailContactsDialogProps {
  open: boolean
  onClose: () => void
}

export default function MailContactsDialog({ open, onClose }: MailContactsDialogProps) {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<MailContact | null>(null)
  const [form, setForm] = useState({ name: '', email: '' })
  const [error, setError] = useState('')

  const { data } = useQuery({
    queryKey: ['mail-contacts', 'book', search],
    queryFn: async () => {
      const res = await apiClient.get('/v1/mail/contacts', { params: { search, per_page: 50 } })
      return {
        items: (res.data.data ?? []) as MailContact[],
        total: (res.data.total ?? 0) as number,
      }
    },
    enabled: open,
  })

  const items = data?.items ?? []
  const total = data?.total ?? 0

  const reload = () => queryClient.invalidateQueries({ queryKey: ['mail-contacts'] })

  const reset = () => {
    setEditing(null)
    setForm({ name: '', email: '' })
    setError('')
  }

  const save = useMutation({
    mutationFn: async () =>
      editing
        ? apiClient.patch(`/v1/mail/contacts/${editing.id}`, { name: form.name, email: form.email })
        : apiClient.post('/v1/mail/contacts', { name: form.name, email: form.email }),
    onSuccess: () => {
      reset()
      reload()
    },
    onError: (e: any) =>
      setError(e?.response?.data?.message || e?.response?.data?.errors?.email?.[0] || 'Не удалось сохранить контакт'),
  })

  const remove = useMutation({
    mutationFn: (id: number) => apiClient.delete(`/v1/mail/contacts/${id}`),
    onSuccess: () => reload(),
    onError: (e: any) => setError(e?.response?.data?.message || 'Не удалось удалить контакт'),
  })

  const startEdit = (contact: MailContact) => {
    setEditing(contact)
    setForm({ name: contact.name ?? '', email: contact.email })
    setError('')
  }

  const handleDelete = (contact: MailContact) => {
    if (window.confirm(`Удалить контакт «${contact.name || contact.email}»?`)) {
      remove.mutate(contact.id)
    }
  }

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Адресная книга</DialogTitle>
      <DialogContent sx={{ pt: 1 }}>
        <Alert severity="info" sx={{ mb: 2 }}>
          Общий список контактов для всех пользователей.
        </Alert>

        <TextField
          fullWidth
          size="small"
          label="Поиск"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          sx={{ mb: 2 }}
        />

        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ mb: 1 }}>
          <TextField
            size="small"
            label="Имя"
            value={form.name}
            onChange={(e) => setForm({ ...form, name: e.target.value })}
            sx={{ flex: 1 }}
          />
          <TextField
            size="small"
            label="Email"
            value={form.email}
            onChange={(e) => setForm({ ...form, email: e.target.value })}
            sx={{ flex: 1 }}
          />
          <Button
            variant="contained"
            onClick={() => save.mutate()}
            disabled={!form.email.trim() || save.isPending}
            sx={{ textTransform: 'none' }}
          >
            {editing ? 'Сохранить' : 'Добавить'}
          </Button>
          {editing && (
            <Button onClick={reset} sx={{ textTransform: 'none' }}>
              Отмена
            </Button>
          )}
        </Stack>

        {error && (
          <Alert severity="error" sx={{ mb: 1 }} onClose={() => setError('')}>
            {error}
          </Alert>
        )}

        <List dense sx={{ maxHeight: 320, overflow: 'auto' }}>
          {items.map((contact) => (
            <ListItem
              key={contact.id}
              secondaryAction={
                <>
                  <Tooltip title="Изменить">
                    <IconButton size="small" onClick={() => startEdit(contact)}>
                      <EditIcon fontSize="small" />
                    </IconButton>
                  </Tooltip>
                  <Tooltip title="Удалить">
                    <IconButton size="small" color="error" onClick={() => handleDelete(contact)}>
                      <DeleteIcon fontSize="small" />
                    </IconButton>
                  </Tooltip>
                </>
              }
            >
              <ListItemText
                primary={contact.name || contact.email}
                secondary={contact.name ? contact.email : undefined}
              />
            </ListItem>
          ))}
          {items.length === 0 && (
            <Typography sx={{ p: 2, textAlign: 'center', color: 'text.secondary', fontSize: '0.85rem' }}>
              Контактов нет
            </Typography>
          )}
        </List>
      </DialogContent>
      <DialogActions>
        <Typography sx={{ flex: 1, ml: 2, fontSize: '0.8rem', color: 'text.secondary' }}>Всего: {total}</Typography>
        <Button onClick={onClose} sx={{ textTransform: 'none' }}>
          Закрыть
        </Button>
      </DialogActions>
    </Dialog>
  )
}
