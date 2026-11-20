import { useEffect, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Checkbox,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  Tab,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Tabs,
  TextField,
} from '@mui/material'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  fetchAdminQuotas,
  fetchAdminSettings,
  storageKeys,
  updateAdminSettings,
  updateUserQuota,
} from '../api/storageApi'
import type { AdminQuotaRow, StorageSettings } from '../types'

interface Props {
  open: boolean
  onClose: () => void
  onChanged: (message: string) => void
  onError: (message: string) => void
}

function formatBytes(bytes: number | null): string {
  if (bytes === null) return '—'
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} КБ`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} МБ`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} ГБ`
}

export default function StorageAdminDialog({ open, onClose, onChanged, onError }: Props) {
  const queryClient = useQueryClient()
  const [tab, setTab] = useState(0)
  const [search, setSearch] = useState('')
  const [quotaDraft, setQuotaDraft] = useState<Record<number, string>>({})

  const quotas = useQuery({
    queryKey: storageKeys.adminQuotas(search),
    queryFn: () => fetchAdminQuotas(search),
    enabled: open && tab === 0,
  })

  const settings = useQuery({
    queryKey: storageKeys.adminSettings(),
    queryFn: fetchAdminSettings,
    enabled: open && tab === 1,
  })

  const [form, setForm] = useState<StorageSettings | null>(null)
  useEffect(() => {
    if (settings.data) setForm(settings.data)
  }, [settings.data])

  const saveQuota = useMutation({
    mutationFn: ({ id, value }: { id: number; value: number | null }) => updateUserQuota(id, value),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'storage', 'quotas'] })
      onChanged('Квота обновлена')
    },
    onError: () => onError('Не удалось обновить квоту'),
  })

  const saveSettings = useMutation({
    mutationFn: (payload: Partial<StorageSettings>) => updateAdminSettings(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: storageKeys.adminSettings() })
      queryClient.invalidateQueries({ queryKey: storageKeys.quota() })
      onChanged('Настройки сохранены')
    },
    onError: () => onError('Не удалось сохранить настройки'),
  })

  const rows: AdminQuotaRow[] = quotas.data?.data ?? []

  const submitQuota = (row: AdminQuotaRow) => {
    const raw = quotaDraft[row.id]
    if (raw === undefined) return
    const trimmed = raw.trim()
    const value = trimmed === '' ? null : Number(trimmed)
    if (trimmed !== '' && Number.isNaN(value)) {
      onError('Некорректное значение квоты')
      return
    }
    saveQuota.mutate({ id: row.id, value })
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle>Администрирование хранилища</DialogTitle>
      <DialogContent dividers>
        <Tabs value={tab} onChange={(_, value) => setTab(value)} sx={{ mb: 2 }}>
          <Tab label="Квоты" sx={{ textTransform: 'none' }} />
          <Tab label="Настройки" sx={{ textTransform: 'none' }} />
        </Tabs>

        {tab === 0 && (
          <>
            <TextField
              size="small"
              label="Поиск пользователя"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              sx={{ mb: 2, minWidth: 280 }}
            />
            {quotas.isLoading && <CircularProgress size={22} />}
            {rows.length === 0 && !quotas.isLoading && <Alert severity="info">Пользователи не найдены</Alert>}
            {rows.length > 0 && (
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Пользователь</TableCell>
                    <TableCell>Занято</TableCell>
                    <TableCell>Лимит (байт, пусто = по умолчанию, -1 = без лимита)</TableCell>
                    <TableCell align="right" />
                  </TableRow>
                </TableHead>
                <TableBody>
                  {rows.map((row) => (
                    <TableRow key={row.id}>
                      <TableCell>
                        {row.name}
                        <Box component="span" sx={{ color: 'text.secondary', fontSize: '0.75rem', display: 'block' }}>
                          {row.email}
                        </Box>
                      </TableCell>
                      <TableCell>{formatBytes(row.used)}</TableCell>
                      <TableCell>
                        <TextField
                          size="small"
                          value={quotaDraft[row.id] ?? (row.quota_bytes === null ? '' : String(row.quota_bytes))}
                          onChange={(e) => setQuotaDraft((prev) => ({ ...prev, [row.id]: e.target.value }))}
                          sx={{ width: 200 }}
                        />
                      </TableCell>
                      <TableCell align="right">
                        <Button size="small" onClick={() => submitQuota(row)} sx={{ textTransform: 'none' }}>
                          Сохранить
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </>
        )}

        {tab === 1 && form && (
          <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2, maxWidth: 420 }}>
            <TextField
              size="small"
              type="number"
              label="Квота по умолчанию, байт"
              value={form.default_quota_bytes}
              onChange={(e) => setForm({ ...form, default_quota_bytes: Number(e.target.value) })}
            />
            <TextField
              size="small"
              type="number"
              label="Максимум версий на файл"
              value={form.version_max_per_file}
              onChange={(e) => setForm({ ...form, version_max_per_file: Number(e.target.value) })}
            />
            <TextField
              size="small"
              type="number"
              label="Хранение версий, дней"
              value={form.version_retention_days}
              onChange={(e) => setForm({ ...form, version_retention_days: Number(e.target.value) })}
            />
            <TextField
              size="small"
              type="number"
              label="Хранение корзины, дней"
              value={form.trash_retention_days}
              onChange={(e) => setForm({ ...form, trash_retention_days: Number(e.target.value) })}
            />
            <TextField
              size="small"
              type="number"
              label="Хранение журнала, дней"
              value={form.audit_retention_days}
              onChange={(e) => setForm({ ...form, audit_retention_days: Number(e.target.value) })}
            />
            <FormControlLabel
              control={
                <Checkbox
                  checked={form.previews_enabled}
                  onChange={(e) => setForm({ ...form, previews_enabled: e.target.checked })}
                />
              }
              label="Формировать превью"
            />
            <FormControlLabel
              control={
                <Checkbox
                  checked={form.office_autosave_enabled}
                  onChange={(e) => setForm({ ...form, office_autosave_enabled: e.target.checked })}
                />
              }
              label="Автосохранение документов Office по ходу редактирования"
            />
            <Box>
              <Button
                variant="contained"
                disabled={saveSettings.isPending}
                onClick={() => saveSettings.mutate(form)}
                sx={{ textTransform: 'none' }}
              >
                Сохранить настройки
              </Button>
            </Box>
          </Box>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} sx={{ textTransform: 'none' }}>
          Закрыть
        </Button>
      </DialogActions>
    </Dialog>
  )
}
