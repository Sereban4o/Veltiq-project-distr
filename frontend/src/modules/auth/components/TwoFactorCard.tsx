import { useEffect, useState } from 'react'
import { Alert, Box, Button, Chip, Paper, Stack, TextField, Typography } from '@mui/material'
import apiClient from '@/modules/shared/api/apiClient'

interface TwoFactorStatus {
  enabled: boolean
  confirmed_at?: string | null
  pending?: boolean
}

export default function TwoFactorCard() {
  const [status, setStatus] = useState<TwoFactorStatus | null>(null)
  const [secret, setSecret] = useState('')
  const [otpauth, setOtpauth] = useState('')
  const [code, setCode] = useState('')
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const load = async () => {
    try {
      const response = await apiClient.get('/v1/auth/2fa')
      setStatus(response.data?.data ?? null)
    } catch {
      setError('Не удалось получить статус 2FA')
    }
  }

  useEffect(() => {
    void load()
  }, [])

  const setup = async () => {
    setBusy(true)
    setError('')
    try {
      const response = await apiClient.post('/v1/auth/2fa/setup')
      setSecret(response.data?.data?.secret ?? '')
      setOtpauth(response.data?.data?.otpauth_url ?? '')
      await load()
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Не удалось начать настройку')
    } finally {
      setBusy(false)
    }
  }

  const confirm = async () => {
    setBusy(true)
    setError('')
    try {
      const response = await apiClient.post('/v1/auth/2fa/confirm', { code: code.trim() })
      setRecoveryCodes(response.data?.data?.recovery_codes ?? [])
      setCode('')
      setSecret('')
      setOtpauth('')
      await load()
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Неверный код')
    } finally {
      setBusy(false)
    }
  }

  const disable = async () => {
    setBusy(true)
    setError('')
    try {
      await apiClient.post('/v1/auth/2fa/disable', { code: code.trim() })
      setCode('')
      await load()
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Неверный код')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Paper sx={{ p: 3, mt: 3 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 1 }}>
        <Typography variant="h6">Двухфакторная аутентификация</Typography>
        {status && (
          <Chip
            size="small"
            color={status.enabled ? 'success' : 'default'}
            label={status.enabled ? 'включена' : 'выключена'}
          />
        )}
      </Box>

      {error && (
        <Alert severity="error" sx={{ mb: 2 }}>
          {error}
        </Alert>
      )}

      {recoveryCodes.length > 0 && (
        <Alert severity="warning" sx={{ mb: 2 }}>
          Сохраните резервные коды (каждый одноразовый):
          <Box sx={{ mt: 1, fontFamily: 'monospace', fontSize: '0.85rem', whiteSpace: 'pre-wrap' }}>
            {recoveryCodes.join('   ')}
          </Box>
        </Alert>
      )}

      {!status?.enabled && !secret && (
        <Button variant="contained" onClick={setup} disabled={busy}>
          Включить 2FA
        </Button>
      )}

      {!status?.enabled && secret && (
        <Stack spacing={2}>
          <Typography variant="body2">
            Добавьте секрет в приложение-аутентификатор (Google Authenticator, Aegis и т.п.):
          </Typography>
          <Box sx={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>{secret}</Box>
          {otpauth && (
            <Typography variant="caption" color="text.secondary" sx={{ wordBreak: 'break-all' }}>
              {otpauth}
            </Typography>
          )}
          <TextField size="small" label="Код из приложения" value={code} onChange={(e) => setCode(e.target.value)} />
          <Button variant="contained" onClick={confirm} disabled={busy || code.trim() === ''}>
            Подтвердить
          </Button>
        </Stack>
      )}

      {status?.enabled && (
        <Stack spacing={2} sx={{ maxWidth: 360 }}>
          <Typography variant="body2" color="text.secondary">
            Чтобы отключить, введите текущий код или резервный код.
          </Typography>
          <TextField size="small" label="Код" value={code} onChange={(e) => setCode(e.target.value)} />
          <Button variant="outlined" color="error" onClick={disable} disabled={busy || code.trim() === ''}>
            Отключить 2FA
          </Button>
        </Stack>
      )}
    </Paper>
  )
}
