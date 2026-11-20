import { useState } from 'react'
import {
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  MenuItem,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import { Add as AddIcon, Delete as DeleteIcon, Edit as EditIcon, Search as SearchIcon } from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { crmApi } from '../api'
import type { CrmContact } from '../types'

const empty: Partial<CrmContact> = {
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  position: '',
  company_id: null,
  notes: '',
}

export default function ContactsTab() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [dialog, setDialog] = useState<Partial<CrmContact> | null>(null)
  const [saving, setSaving] = useState(false)

  const { data = [], isLoading } = useQuery<CrmContact[]>({
    queryKey: ['crm-contacts', search],
    queryFn: () => crmApi.contacts(search),
  })

  const { data: companies = [] } = useQuery({ queryKey: ['crm-companies', ''], queryFn: () => crmApi.companies('') })

  const reload = () => queryClient.invalidateQueries({ queryKey: ['crm-contacts'] })

  const save = async () => {
    if (!dialog?.first_name?.trim()) return
    setSaving(true)
    try {
      if (dialog.id) await crmApi.updateContact(dialog.id, dialog)
      else await crmApi.createContact(dialog)
      setDialog(null)
      reload()
    } finally {
      setSaving(false)
    }
  }

  const remove = async (contact: CrmContact) => {
    if (!window.confirm(`Удалить контакт «${contact.first_name} ${contact.last_name ?? ''}»?`)) return
    await crmApi.deleteContact(contact.id)
    reload()
  }

  const set = (field: keyof CrmContact, value: unknown) => setDialog((prev) => ({ ...prev, [field]: value }))

  return (
    <Stack spacing={2}>
      <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
        <TextField
          size="small"
          placeholder="Поиск контактов..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          InputProps={{ startAdornment: <SearchIcon fontSize="small" sx={{ mr: 1, color: 'text.secondary' }} /> }}
          sx={{ maxWidth: 320 }}
        />
        <Box sx={{ flex: 1 }} />
        <Button
          variant="contained"
          startIcon={<AddIcon />}
          onClick={() => setDialog({ ...empty })}
          sx={{ textTransform: 'none' }}
        >
          Контакт
        </Button>
      </Box>

      {isLoading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 5 }}>
          <CircularProgress />
        </Box>
      ) : (
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Имя</TableCell>
              <TableCell>Компания</TableCell>
              <TableCell>Должность</TableCell>
              <TableCell>Телефон</TableCell>
              <TableCell>Email</TableCell>
              <TableCell align="right" />
            </TableRow>
          </TableHead>
          <TableBody>
            {data.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} align="center" sx={{ color: 'text.secondary', py: 3 }}>
                  Контактов пока нет
                </TableCell>
              </TableRow>
            )}
            {data.map((contact) => (
              <TableRow key={contact.id} hover>
                <TableCell>
                  <Typography sx={{ fontSize: '0.88rem', fontWeight: 600 }}>
                    {contact.first_name} {contact.last_name}
                  </Typography>
                </TableCell>
                <TableCell sx={{ fontSize: '0.83rem', color: 'text.secondary' }}>
                  {contact.company?.name || '—'}
                </TableCell>
                <TableCell sx={{ fontSize: '0.83rem', color: 'text.secondary' }}>{contact.position || '—'}</TableCell>
                <TableCell sx={{ fontSize: '0.83rem', color: 'text.secondary' }}>{contact.phone || '—'}</TableCell>
                <TableCell sx={{ fontSize: '0.83rem', color: 'text.secondary' }}>{contact.email || '—'}</TableCell>
                <TableCell align="right">
                  <Tooltip title="Редактировать">
                    <IconButton size="small" onClick={() => setDialog({ ...contact })}>
                      <EditIcon fontSize="small" />
                    </IconButton>
                  </Tooltip>
                  <Tooltip title="Удалить">
                    <IconButton size="small" onClick={() => remove(contact)}>
                      <DeleteIcon fontSize="small" />
                    </IconButton>
                  </Tooltip>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

      <Dialog open={!!dialog} onClose={() => setDialog(null)} fullWidth maxWidth="sm">
        <DialogTitle>{dialog?.id ? 'Контакт' : 'Новый контакт'}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField
              label="Имя"
              value={dialog?.first_name ?? ''}
              onChange={(e) => set('first_name', e.target.value)}
              required
            />
            <TextField
              label="Фамилия"
              value={dialog?.last_name ?? ''}
              onChange={(e) => set('last_name', e.target.value)}
            />
            <TextField
              label="Компания"
              select
              value={dialog?.company_id ?? ''}
              onChange={(e) => set('company_id', e.target.value ? Number(e.target.value) : null)}
            >
              <MenuItem value="">—</MenuItem>
              {companies.map((c) => (
                <MenuItem key={c.id} value={c.id}>
                  {c.name}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              label="Должность"
              value={dialog?.position ?? ''}
              onChange={(e) => set('position', e.target.value)}
            />
            <TextField label="Телефон" value={dialog?.phone ?? ''} onChange={(e) => set('phone', e.target.value)} />
            <TextField label="Email" value={dialog?.email ?? ''} onChange={(e) => set('email', e.target.value)} />
            <TextField
              label="Заметки"
              value={dialog?.notes ?? ''}
              onChange={(e) => set('notes', e.target.value)}
              multiline
              minRows={3}
            />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialog(null)} sx={{ textTransform: 'none' }}>
            Отмена
          </Button>
          <Button variant="contained" onClick={save} disabled={saving} sx={{ textTransform: 'none' }}>
            {saving ? 'Сохранение...' : 'Сохранить'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}
