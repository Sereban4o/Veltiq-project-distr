import { useEffect, useState } from 'react'
import {
  Box,
  Paper,
  Typography,
  Divider,
  Checkbox,
  FormControlLabel,
  FormGroup,
  Button,
  MenuItem,
  TextField,
  Alert,
  CircularProgress,
} from '@mui/material'
import {
  useNotificationPreferences,
  useSaveNotificationPreferences,
  type NotificationPreferenceUpdate,
} from '@/modules/notifications/api/notificationApi'
import type { DigestFrequency } from '@/types'

const CHANNELS = [
  // The in-app feed is the delivery ledger (push and the email digest read
  // from it), so it cannot be turned off — it is always recorded.
  { key: 'in_app' as const, label: 'В приложении', locked: true },
  { key: 'email' as const, label: 'Email', locked: false },
  { key: 'websocket' as const, label: 'Push', locked: false },
]

export default function NotificationSettingsCard() {
  const { data, isLoading } = useNotificationPreferences()
  const save = useSaveNotificationPreferences()

  const [prefs, setPrefs] = useState<Record<string, NotificationPreferenceUpdate>>({})
  const [digestFrequency, setDigestFrequency] = useState<DigestFrequency>('off')
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (!data) return
    const next: Record<string, NotificationPreferenceUpdate> = {}
    Object.entries(data.preferences).forEach(([type, preference]) => {
      next[type] = {
        type,
        in_app: preference.in_app,
        email: preference.email,
        websocket: preference.websocket,
      }
    })
    setPrefs(next)
    setDigestFrequency(data.digest_frequency)
  }, [data])

  const toggle = (type: string, channel: 'in_app' | 'email' | 'websocket', value: boolean) => {
    setPrefs((prev) => ({ ...prev, [type]: { ...prev[type], type, [channel]: value } }))
  }

  const handleSave = async () => {
    setSaved(false)
    await save.mutateAsync({
      preferences: Object.values(prefs).map((preference) => ({ ...preference, in_app: true })),
      digest_frequency: digestFrequency,
    })
    setSaved(true)
    setTimeout(() => setSaved(false), 3000)
  }

  if (isLoading) {
    return (
      <Paper sx={{ p: 4, maxWidth: 700, mt: 3, display: 'flex', justifyContent: 'center' }}>
        <CircularProgress />
      </Paper>
    )
  }

  return (
    <Paper sx={{ p: 4, maxWidth: 700, mt: 3 }}>
      <Typography variant="h6" sx={{ mb: 1, fontWeight: 600 }}>
        🔔 Уведомления
      </Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
        Выберите каналы доставки для каждого типа уведомлений и частоту email-дайджеста.
      </Typography>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 2 }}>
        Канал «В приложении» всегда включён: уведомления сохраняются в ленте как журнал доставки (из него берутся push и
        email-дайджест). Отключать можно только push и email.
      </Typography>

      {saved && (
        <Alert severity="success" sx={{ mb: 2 }} onClose={() => setSaved(false)}>
          Настройки уведомлений сохранены
        </Alert>
      )}

      {Object.entries(data?.types ?? {}).map(([groupKey, group]) => (
        <Box key={groupKey} sx={{ mb: 2 }}>
          <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 1 }}>
            {group.label}
          </Typography>
          <Divider sx={{ mb: 1 }} />
          {Object.entries(group.types).map(([type, label]) => {
            const preference = data?.preferences[type]
            if (!preference?.configurable) {
              return (
                <Typography key={type} variant="body2" color="text.secondary" sx={{ py: 0.5 }}>
                  {label} — всегда доставляется
                </Typography>
              )
            }
            return (
              <FormGroup key={type} row sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
                <Typography sx={{ minWidth: 220, flexGrow: 1 }} variant="body2">
                  {label}
                </Typography>
                {CHANNELS.map((channel) => (
                  <FormControlLabel
                    key={channel.key}
                    control={
                      <Checkbox
                        size="small"
                        checked={channel.locked ? true : (prefs[type]?.[channel.key] ?? false)}
                        disabled={channel.locked}
                        onChange={(e) => toggle(type, channel.key, e.target.checked)}
                      />
                    }
                    label={channel.label}
                  />
                ))}
              </FormGroup>
            )
          })}
        </Box>
      ))}

      <Divider sx={{ my: 3 }} />

      <TextField
        select
        label="Частота email-дайджеста"
        value={digestFrequency}
        onChange={(e) => setDigestFrequency(e.target.value as DigestFrequency)}
        sx={{ minWidth: 240 }}
      >
        {(data?.digest_frequencies ?? []).map((option) => (
          <MenuItem key={option.value} value={option.value}>
            {option.label}
          </MenuItem>
        ))}
      </TextField>

      <Box sx={{ mt: 3 }}>
        <Button variant="contained" onClick={handleSave} disabled={save.isPending}>
          {save.isPending ? 'Сохранение...' : 'Сохранить'}
        </Button>
      </Box>
    </Paper>
  )
}
