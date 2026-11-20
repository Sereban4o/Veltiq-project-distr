import { useEffect, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Divider,
  Grid,
  IconButton,
  MenuItem,
  Paper,
  Stack,
  Switch,
  TextField,
  Typography,
  CircularProgress,
} from '@mui/material'
import {
  Save as SaveIcon,
  Send as TestIcon,
  Refresh as SyncIcon,
  PersonAdd as PersonAddIcon,
  Delete as DeleteIcon,
} from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapData, unwrapList } from '@/modules/shared/utils/apiResponse'
import { useAuth } from '@/modules/auth/store/authStore'
import type { MailAccountMember, MailEncryption, MailProtocol, MailSettings } from '../types'

const ENCRYPTIONS: { value: MailEncryption; label: string }[] = [
  { value: 'ssl', label: 'SSL' },
  { value: 'tls', label: 'STARTTLS' },
  { value: 'none', label: 'Без шифрования' },
]

function emptyForm(): Record<string, string> {
  return {
    email: '',
    display_name: '',
    incoming_protocol: 'imap',
    imap_host: '',
    imap_port: '993',
    imap_encryption: 'ssl',
    imap_username: '',
    imap_password: '',
    pop_host: '',
    pop_port: '995',
    pop_encryption: 'ssl',
    pop_username: '',
    pop_password: '',
    smtp_host: '',
    smtp_port: '587',
    smtp_encryption: 'tls',
    smtp_username: '',
    smtp_password: '',
  }
}

interface DirectoryUser {
  id: number
  full_name?: string
  first_name?: string
  last_name?: string
  email?: string
}

function MailSharingSection() {
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const [selectedUser, setSelectedUser] = useState('')
  const [canSend, setCanSend] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const { data: members = [] } = useQuery<MailAccountMember[]>({
    queryKey: ['mail-members'],
    queryFn: async () => unwrapList<MailAccountMember>(await apiClient.get('/v1/mail/settings/members')),
  })

  const { data: users = [] } = useQuery<DirectoryUser[]>({
    queryKey: ['users-all'],
    queryFn: async () => unwrapList<DirectoryUser>(await apiClient.get('/v1/users/all')),
  })

  const memberIds = new Set(members.map((m) => m.id))
  const candidates = users.filter((u) => u.id !== user?.id && !memberIds.has(u.id))

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['mail-members'] })

  const add = async () => {
    if (!selectedUser) return
    setBusy(true)
    setError(null)
    try {
      await apiClient.post('/v1/mail/settings/members', { user_id: Number(selectedUser), can_send: canSend })
      setSelectedUser('')
      setCanSend(false)
      await refresh()
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Не удалось добавить участника')
    } finally {
      setBusy(false)
    }
  }

  const setMemberSend = async (id: number, value: boolean) => {
    setBusy(true)
    setError(null)
    try {
      await apiClient.post('/v1/mail/settings/members', { user_id: id, can_send: value })
      await refresh()
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Не удалось изменить права')
    } finally {
      setBusy(false)
    }
  }

  const remove = async (id: number) => {
    setBusy(true)
    setError(null)
    try {
      await apiClient.delete(`/v1/mail/settings/members/${id}`)
      await refresh()
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Не удалось убрать участника')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Box>
      <Divider textAlign="left">
        <Typography variant="caption" color="text.secondary">
          Общий ящик (команда)
        </Typography>
      </Divider>
      <Typography variant="body2" color="text.secondary" sx={{ my: 1 }}>
        Предоставьте доступ к этому ящику сотрудникам. Право «Отправлять» позволяет отвечать от имени ящика.
      </Typography>
      {error && (
        <Alert severity="error" sx={{ mb: 1 }} onClose={() => setError(null)}>
          {error}
        </Alert>
      )}
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems="center" sx={{ mb: 1 }}>
        <TextField
          select
          size="small"
          label="Сотрудник"
          value={selectedUser}
          onChange={(e) => setSelectedUser(e.target.value)}
          sx={{ minWidth: 240, flex: 1 }}
          disabled={busy || candidates.length === 0}
        >
          {candidates.map((u) => (
            <MenuItem key={u.id} value={u.id}>
              {u.full_name || [u.first_name, u.last_name].filter(Boolean).join(' ') || u.email || `#${u.id}`}
            </MenuItem>
          ))}
        </TextField>
        <Box sx={{ display: 'flex', alignItems: 'center' }}>
          <Typography variant="body2" sx={{ mr: 0.5 }}>
            Отправлять
          </Typography>
          <Switch checked={canSend} onChange={(e) => setCanSend(e.target.checked)} disabled={busy} />
        </Box>
        <Button variant="outlined" startIcon={<PersonAddIcon />} onClick={add} disabled={busy || !selectedUser}>
          Добавить
        </Button>
      </Stack>
      {members.length === 0 ? (
        <Typography variant="body2" color="text.secondary">
          Участники не добавлены.
        </Typography>
      ) : (
        members.map((m) => (
          <Box key={m.id} sx={{ display: 'flex', alignItems: 'center', gap: 1, py: 0.5 }}>
            <Typography variant="body2" noWrap sx={{ flex: 1, minWidth: 0 }}>
              {m.name || m.email || `#${m.id}`}
            </Typography>
            <Typography variant="caption" color="text.secondary">
              Отправлять
            </Typography>
            <Switch
              size="small"
              checked={m.can_send}
              onChange={(e) => setMemberSend(m.id, e.target.checked)}
              disabled={busy}
            />
            <IconButton size="small" onClick={() => remove(m.id)} disabled={busy}>
              <DeleteIcon fontSize="small" />
            </IconButton>
          </Box>
        ))
      )}
    </Box>
  )
}

export default function MailSettingsCard() {
  const queryClient = useQueryClient()
  const [form, setForm] = useState<Record<string, string>>(emptyForm())
  const [passwordSet, setPasswordSet] = useState({ imap: false, pop: false, smtp: false })
  const [status, setStatus] = useState<{ type: 'success' | 'error'; text: string } | null>(null)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(false)

  const { data, isLoading } = useQuery<MailSettings | null>({
    queryKey: ['mail-settings'],
    queryFn: async () => unwrapData<MailSettings>(await apiClient.get('/v1/mail/settings')),
  })

  useEffect(() => {
    if (!data) return
    setForm({
      email: data.email ?? '',
      display_name: data.display_name ?? '',
      incoming_protocol: data.incoming_protocol ?? 'imap',
      imap_host: data.imap_host ?? '',
      imap_port: data.imap_port ? String(data.imap_port) : '993',
      imap_encryption: data.imap_encryption ?? 'ssl',
      imap_username: data.imap_username ?? '',
      imap_password: '',
      pop_host: data.pop_host ?? '',
      pop_port: data.pop_port ? String(data.pop_port) : '995',
      pop_encryption: data.pop_encryption ?? 'ssl',
      pop_username: data.pop_username ?? '',
      pop_password: '',
      smtp_host: data.smtp_host ?? '',
      smtp_port: data.smtp_port ? String(data.smtp_port) : '587',
      smtp_encryption: data.smtp_encryption ?? 'tls',
      smtp_username: data.smtp_username ?? '',
      smtp_password: '',
    })
    setPasswordSet({
      imap: data.imap_password_set,
      pop: data.pop_password_set,
      smtp: data.smtp_password_set,
    })
  }, [data])

  const set = (field: string, value: string) => setForm((prev) => ({ ...prev, [field]: value }))

  const buildPayload = () => {
    const useImap = form.incoming_protocol === 'imap'

    return {
      email: form.email,
      display_name: form.display_name || null,
      incoming_protocol: form.incoming_protocol as MailProtocol,
      // Only the selected protocol is stored; the other one is cleared.
      imap_host: useImap ? form.imap_host || null : null,
      imap_port: useImap && form.imap_port ? Number(form.imap_port) : null,
      imap_encryption: useImap ? form.imap_encryption : null,
      imap_username: useImap ? form.imap_username || null : null,
      imap_password: useImap ? form.imap_password || undefined : undefined,
      pop_host: !useImap ? form.pop_host || null : null,
      pop_port: !useImap && form.pop_port ? Number(form.pop_port) : null,
      pop_encryption: !useImap ? form.pop_encryption : null,
      pop_username: !useImap ? form.pop_username || null : null,
      pop_password: !useImap ? form.pop_password || undefined : undefined,
      smtp_host: form.smtp_host || null,
      smtp_port: form.smtp_port ? Number(form.smtp_port) : null,
      smtp_encryption: form.smtp_encryption,
      smtp_username: form.smtp_username || null,
      smtp_password: form.smtp_password || undefined,
    }
  }

  const handleSave = async () => {
    setStatus(null)
    setSaving(true)
    try {
      await apiClient.put('/v1/mail/settings', buildPayload())
      await queryClient.invalidateQueries({ queryKey: ['mail-settings'] })
      setStatus({ type: 'success', text: 'Настройки почты сохранены' })
    } catch (e: any) {
      setStatus({ type: 'error', text: e?.response?.data?.message || 'Не удалось сохранить настройки' })
    } finally {
      setSaving(false)
    }
  }

  const handleTest = async () => {
    setStatus(null)
    setTesting(true)
    try {
      const res = await apiClient.post('/v1/mail/settings/test', { protocol: form.incoming_protocol })
      setStatus({ type: 'success', text: res.data?.message || 'Подключение успешно' })
    } catch (e: any) {
      setStatus({ type: 'error', text: e?.response?.data?.message || 'Не удалось подключиться' })
    } finally {
      setTesting(false)
    }
  }

  if (isLoading) {
    return (
      <Paper sx={{ p: 4, maxWidth: 700, mt: 3, display: 'flex', justifyContent: 'center' }}>
        <CircularProgress size={24} />
      </Paper>
    )
  }

  return (
    <Paper sx={{ p: 4, maxWidth: 700, mt: 3 }}>
      <Typography variant="h6" sx={{ fontWeight: 600, mb: 1 }}>
        Почтовый ящик
      </Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
        Настройте подключение к своему ящику (IMAP/POP для входящих, SMTP для исходящих).
      </Typography>

      {status && (
        <Alert severity={status.type} sx={{ mb: 2 }} onClose={() => setStatus(null)}>
          {status.text}
        </Alert>
      )}
      {data?.last_sync_error && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          Последняя синхронизация: {data.last_sync_error}
        </Alert>
      )}

      <Stack spacing={3}>
        <Grid container spacing={2}>
          <Grid item xs={12} sm={8}>
            <TextField fullWidth label="Email" value={form.email} onChange={(e) => set('email', e.target.value)} />
          </Grid>
          <Grid item xs={12} sm={4}>
            <TextField
              fullWidth
              label="Имя отправителя"
              value={form.display_name}
              onChange={(e) => set('display_name', e.target.value)}
            />
          </Grid>
        </Grid>

        <TextField
          select
          label="Протокол входящей почты"
          value={form.incoming_protocol}
          onChange={(e) => set('incoming_protocol', e.target.value)}
          sx={{ maxWidth: 320 }}
        >
          <MenuItem value="imap">IMAP</MenuItem>
          <MenuItem value="pop">POP3</MenuItem>
        </TextField>

        {form.incoming_protocol === 'imap' && (
          <>
            <Divider textAlign="left">
              <Typography variant="caption" color="text.secondary">
                Входящие (IMAP)
              </Typography>
            </Divider>
            <Grid container spacing={2}>
              <Grid item xs={12} sm={5}>
                <TextField
                  fullWidth
                  label="IMAP хост"
                  value={form.imap_host}
                  onChange={(e) => set('imap_host', e.target.value)}
                />
              </Grid>
              <Grid item xs={6} sm={2}>
                <TextField
                  fullWidth
                  label="Порт"
                  value={form.imap_port}
                  onChange={(e) => set('imap_port', e.target.value)}
                />
              </Grid>
              <Grid item xs={6} sm={2}>
                <TextField
                  select
                  fullWidth
                  label="Шифр."
                  value={form.imap_encryption}
                  onChange={(e) => set('imap_encryption', e.target.value)}
                >
                  {ENCRYPTIONS.map((o) => (
                    <MenuItem key={o.value} value={o.value}>
                      {o.label}
                    </MenuItem>
                  ))}
                </TextField>
              </Grid>
              <Grid item xs={12} sm={3}>
                <TextField
                  fullWidth
                  label="Логин"
                  value={form.imap_username}
                  onChange={(e) => set('imap_username', e.target.value)}
                />
              </Grid>
              <Grid item xs={12}>
                <TextField
                  fullWidth
                  type="password"
                  label="Пароль"
                  value={form.imap_password}
                  onChange={(e) => set('imap_password', e.target.value)}
                  placeholder={passwordSet.imap ? '•••••••• (сохранён)' : ''}
                  autoComplete="new-password"
                />
              </Grid>
            </Grid>
          </>
        )}

        {form.incoming_protocol === 'pop' && (
          <>
            <Divider textAlign="left">
              <Typography variant="caption" color="text.secondary">
                Входящие (POP3)
              </Typography>
            </Divider>
            <Grid container spacing={2}>
              <Grid item xs={12} sm={5}>
                <TextField
                  fullWidth
                  label="POP3 хост"
                  value={form.pop_host}
                  onChange={(e) => set('pop_host', e.target.value)}
                />
              </Grid>
              <Grid item xs={6} sm={2}>
                <TextField
                  fullWidth
                  label="Порт"
                  value={form.pop_port}
                  onChange={(e) => set('pop_port', e.target.value)}
                />
              </Grid>
              <Grid item xs={6} sm={2}>
                <TextField
                  select
                  fullWidth
                  label="Шифр."
                  value={form.pop_encryption}
                  onChange={(e) => set('pop_encryption', e.target.value)}
                >
                  {ENCRYPTIONS.map((o) => (
                    <MenuItem key={o.value} value={o.value}>
                      {o.label}
                    </MenuItem>
                  ))}
                </TextField>
              </Grid>
              <Grid item xs={12} sm={3}>
                <TextField
                  fullWidth
                  label="Логин"
                  value={form.pop_username}
                  onChange={(e) => set('pop_username', e.target.value)}
                />
              </Grid>
              <Grid item xs={12}>
                <TextField
                  fullWidth
                  type="password"
                  label="Пароль"
                  value={form.pop_password}
                  onChange={(e) => set('pop_password', e.target.value)}
                  placeholder={passwordSet.pop ? '•••••••• (сохранён)' : ''}
                  autoComplete="new-password"
                />
              </Grid>
            </Grid>
          </>
        )}

        <Divider textAlign="left">
          <Typography variant="caption" color="text.secondary">
            Исходящие (SMTP)
          </Typography>
        </Divider>
        <Grid container spacing={2}>
          <Grid item xs={12} sm={5}>
            <TextField
              fullWidth
              label="SMTP хост"
              value={form.smtp_host}
              onChange={(e) => set('smtp_host', e.target.value)}
            />
          </Grid>
          <Grid item xs={6} sm={2}>
            <TextField
              fullWidth
              label="Порт"
              value={form.smtp_port}
              onChange={(e) => set('smtp_port', e.target.value)}
            />
          </Grid>
          <Grid item xs={6} sm={2}>
            <TextField
              select
              fullWidth
              label="Шифр."
              value={form.smtp_encryption}
              onChange={(e) => set('smtp_encryption', e.target.value)}
            >
              {ENCRYPTIONS.map((o) => (
                <MenuItem key={o.value} value={o.value}>
                  {o.label}
                </MenuItem>
              ))}
            </TextField>
          </Grid>
          <Grid item xs={12} sm={3}>
            <TextField
              fullWidth
              label="Логин"
              value={form.smtp_username}
              onChange={(e) => set('smtp_username', e.target.value)}
            />
          </Grid>
          <Grid item xs={12}>
            <TextField
              fullWidth
              type="password"
              label="Пароль"
              value={form.smtp_password}
              onChange={(e) => set('smtp_password', e.target.value)}
              placeholder={passwordSet.smtp ? '•••••••• (сохранён)' : ''}
              autoComplete="new-password"
            />
          </Grid>
        </Grid>

        <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap' }}>
          <Button variant="contained" startIcon={<SaveIcon />} onClick={handleSave} disabled={saving}>
            {saving ? 'Сохранение...' : 'Сохранить'}
          </Button>
          <Button variant="outlined" startIcon={<TestIcon />} onClick={handleTest} disabled={testing}>
            Проверить входящие
          </Button>
          <Button
            variant="outlined"
            startIcon={<SyncIcon />}
            onClick={async () => {
              setStatus(null)
              try {
                const res = await apiClient.post('/v1/mail/sync')
                setStatus({ type: 'success', text: `Загружено новых писем: ${res.data?.stored ?? 0}` })
                await queryClient.invalidateQueries({ queryKey: ['mail-settings'] })
              } catch (e: any) {
                setStatus({ type: 'error', text: e?.response?.data?.message || 'Не удалось синхронизировать' })
              }
            }}
          >
            Синхронизировать
          </Button>
        </Box>
      </Stack>

      <Box sx={{ mt: 3 }}>
        <MailSharingSection />
      </Box>
    </Paper>
  )
}
