import { useEffect, useRef, useState } from 'react'
import { Alert, Box, Button, Paper, Stack, Typography } from '@mui/material'
import { QrCode2 as QrIcon } from '@mui/icons-material'
import QRCode from 'qrcode'
import apiClient from '@/modules/shared/api/apiClient'

interface QrStartResponse {
  code: string
  expires_at: string
  payload: string
}

/**
 * Shows a one-time QR code that the mobile app scans to sign in. The code is
 * single-use and expires after ~2 minutes; the countdown lets the user refresh.
 */
export default function QrLoginCard() {
  const [data, setData] = useState<QrStartResponse | null>(null)
  const [image, setImage] = useState('')
  const [seconds, setSeconds] = useState(0)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const timer = useRef<number | null>(null)

  const start = async () => {
    setBusy(true)
    setError('')
    try {
      const response = await apiClient.post('/v1/auth/qr/start')
      const payload = response.data?.data as QrStartResponse
      setData(payload)
      setImage(await QRCode.toDataURL(payload.payload, { width: 240, margin: 1 }))
      const expires = new Date(payload.expires_at).getTime()
      setSeconds(Math.max(0, Math.round((expires - Date.now()) / 1000)))
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Не удалось создать код')
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    if (!data) return
    if (timer.current) window.clearInterval(timer.current)
    timer.current = window.setInterval(() => {
      setSeconds((prev) => {
        if (prev <= 1) {
          if (timer.current) window.clearInterval(timer.current)
          return 0
        }
        return prev - 1
      })
    }, 1000)

    return () => {
      if (timer.current) window.clearInterval(timer.current)
    }
  }, [data])

  return (
    <Paper sx={{ p: 3, mt: 3, maxWidth: 700 }}>
      <Typography variant="h6" sx={{ fontWeight: 600, mb: 1 }}>
        Вход в приложение по QR
      </Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
        Откройте код и отсканируйте его камерой в мобильном приложении («Войти по QR»). Код одноразовый и действует
        около 2 минут.
      </Typography>

      {error && (
        <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError('')}>
          {error}
        </Alert>
      )}

      {!data && (
        <Button
          variant="contained"
          startIcon={<QrIcon />}
          onClick={start}
          disabled={busy}
          sx={{ textTransform: 'none' }}
        >
          {busy ? 'Создание…' : 'Показать QR-код'}
        </Button>
      )}

      {data && image && (
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={3} alignItems="center">
          <Box
            component="img"
            src={image}
            alt="QR-код для входа"
            sx={{ width: 240, height: 240, borderRadius: 2, bgcolor: '#fff', p: 1 }}
          />
          <Stack spacing={1}>
            <Typography variant="body2" color={seconds > 0 ? 'text.primary' : 'error.main'}>
              {seconds > 0 ? `Код действует ещё ${seconds} с` : 'Код истёк'}
            </Typography>
            <Button variant="outlined" onClick={start} disabled={busy} sx={{ textTransform: 'none' }}>
              {seconds > 0 ? 'Обновить код' : 'Новый код'}
            </Button>
          </Stack>
        </Stack>
      )}
    </Paper>
  )
}
