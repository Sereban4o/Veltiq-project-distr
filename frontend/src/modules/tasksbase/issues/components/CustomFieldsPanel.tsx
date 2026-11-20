import { useEffect, useState } from 'react'
import { Alert, Box, Button, Card, CardContent, Checkbox, MenuItem, Stack, TextField, Typography } from '@mui/material'
import { Save as SaveIcon } from '@mui/icons-material'
import apiClient from '@/modules/shared/api/apiClient'

type FieldType = 'text' | 'textarea' | 'number' | 'date' | 'checkbox' | 'select'

interface FieldDefinition {
  key: string
  label: string
  type: FieldType
  options: string[]
  required: boolean
}

type FieldValue = string | number | boolean | null

/**
 * Admin-defined extra fields of an issue. Definitions come from
 * `GET /api/v1/custom-fields`; values are written back to the issue (the
 * server enforces edit permissions and answers 403 otherwise).
 */
export default function CustomFieldsPanel({
  issueId,
  values,
  onSaved,
}: {
  issueId: number | string
  values: Record<string, FieldValue> | null | undefined
  onSaved: () => void
}) {
  const [definitions, setDefinitions] = useState<FieldDefinition[]>([])
  const [draft, setDraft] = useState<Record<string, FieldValue>>({})
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let cancelled = false

    apiClient
      .get('/v1/custom-fields', { params: { entity: 'issue' } })
      .then((response) => {
        if (cancelled) return
        setDefinitions((response.data?.data ?? []) as FieldDefinition[])
      })
      .catch(() => {
        /* extra fields are optional */
      })

    return () => {
      cancelled = true
    }
  }, [])

  useEffect(() => {
    setDraft({ ...(values ?? {}) })
  }, [values])

  if (definitions.length === 0) return null

  const save = async () => {
    setBusy(true)
    setError('')
    try {
      await apiClient.patch(`/v1/issues/${issueId}/custom-fields`, { values: draft })
      onSaved()
    } catch (e: any) {
      const errors = e?.response?.data?.errors
      setError(errors ? Object.values(errors).join('; ') : e?.response?.data?.message || 'Не удалось сохранить')
    } finally {
      setBusy(false)
    }
  }

  const set = (key: string, value: FieldValue) => setDraft((prev) => ({ ...prev, [key]: value }))

  return (
    <Card sx={{ mb: 3 }}>
      <CardContent>
        <Box sx={{ display: 'flex', alignItems: 'center', mb: 2 }}>
          <Typography variant="h6" sx={{ flex: 1 }}>
            Доп. поля
          </Typography>
          <Button size="small" startIcon={<SaveIcon />} onClick={save} disabled={busy}>
            {busy ? 'Сохранение…' : 'Сохранить'}
          </Button>
        </Box>

        {error && (
          <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError('')}>
            {error}
          </Alert>
        )}

        <Stack spacing={2}>
          {definitions.map((field) => {
            const value = draft[field.key]

            if (field.type === 'checkbox') {
              return (
                <Box key={field.key} sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                  <Checkbox checked={Boolean(value)} onChange={(e) => set(field.key, e.target.checked)} />
                  <Typography variant="body2">{field.label}</Typography>
                </Box>
              )
            }

            if (field.type === 'select') {
              return (
                <TextField
                  key={field.key}
                  select
                  size="small"
                  label={field.label}
                  value={typeof value === 'string' ? value : ''}
                  onChange={(e) => set(field.key, e.target.value)}
                  sx={{ maxWidth: 360 }}
                >
                  <MenuItem value="">—</MenuItem>
                  {field.options.map((option) => (
                    <MenuItem key={option} value={option}>
                      {option}
                    </MenuItem>
                  ))}
                </TextField>
              )
            }

            const isLong = field.type === 'textarea'

            return (
              <TextField
                key={field.key}
                size="small"
                label={field.label}
                value={value === null || value === undefined ? '' : String(value)}
                multiline={isLong}
                minRows={isLong ? 3 : undefined}
                type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
                onChange={(e) => set(field.key, e.target.value)}
                InputLabelProps={field.type === 'date' ? { shrink: true } : undefined}
                sx={{ maxWidth: isLong ? '100%' : 360 }}
              />
            )
          })}
        </Stack>
      </CardContent>
    </Card>
  )
}
