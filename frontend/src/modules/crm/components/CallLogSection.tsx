import { useState } from 'react'
import { Box, Button, CircularProgress, MenuItem, Stack, TextField, Typography } from '@mui/material'
import { Call as CallIcon, PhoneInTalk as InIcon, PhoneForwarded as OutIcon } from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { crmApi } from '../api'
import type { CrmActivity } from '../types'

const OUTCOMES: Record<string, string> = {
  connected: 'Разговор',
  no_answer: 'Не ответили',
  busy: 'Занято',
  callback: 'Перезвонить',
}

interface Props {
  entity: 'contacts' | 'companies' | 'deals'
  id: number
}

export default function CallLogSection({ entity, id }: Props) {
  const queryClient = useQueryClient()
  const [saving, setSaving] = useState(false)
  const [form, setForm] = useState({
    subject: '',
    direction: 'outbound' as 'inbound' | 'outbound',
    outcome: 'connected',
    duration_seconds: '',
    phone: '',
  })

  const filterParam = entity === 'contacts' ? 'contact_id' : entity === 'companies' ? 'company_id' : 'deal_id'

  const { data: activities = [], isLoading } = useQuery<CrmActivity[]>({
    queryKey: ['crm-calls', entity, id],
    queryFn: () => crmApi.activities({ [filterParam]: id }),
  })

  const calls = activities.filter((a) => a.type === 'call')

  const reload = () => {
    queryClient.invalidateQueries({ queryKey: ['crm-calls', entity, id] })
    queryClient.invalidateQueries({ queryKey: ['crm-activities'] })
  }

  const add = async () => {
    if (!form.subject.trim()) return
    setSaving(true)
    try {
      await crmApi.createActivity({
        type: 'call',
        subject: form.subject,
        direction: form.direction,
        outcome: form.outcome as CrmActivity['outcome'],
        duration_seconds: form.duration_seconds ? Number(form.duration_seconds) : null,
        phone: form.phone || null,
        is_completed: true,
        [filterParam]: id,
      } as Partial<CrmActivity>)
      setForm({ subject: '', direction: 'outbound', outcome: 'connected', duration_seconds: '', phone: '' })
      reload()
    } finally {
      setSaving(false)
    }
  }

  const set = (field: keyof typeof form, value: string) => setForm((prev) => ({ ...prev, [field]: value }))

  return (
    <Stack spacing={2}>
      <Box sx={{ display: 'flex', gap: 1, flexWrap: 'wrap' }}>
        <TextField
          size="small"
          label="Тема звонка"
          value={form.subject}
          onChange={(e) => set('subject', e.target.value)}
          sx={{ flex: 1, minWidth: 180 }}
        />
        <TextField
          select
          size="small"
          label="Направление"
          value={form.direction}
          onChange={(e) => set('direction', e.target.value)}
          sx={{ width: 150 }}
        >
          <MenuItem value="outbound">Исходящий</MenuItem>
          <MenuItem value="inbound">Входящий</MenuItem>
        </TextField>
        <TextField
          select
          size="small"
          label="Итог"
          value={form.outcome}
          onChange={(e) => set('outcome', e.target.value)}
          sx={{ width: 150 }}
        >
          {Object.entries(OUTCOMES).map(([value, label]) => (
            <MenuItem key={value} value={value}>
              {label}
            </MenuItem>
          ))}
        </TextField>
        <TextField
          size="small"
          label="Секунды"
          type="number"
          value={form.duration_seconds}
          onChange={(e) => set('duration_seconds', e.target.value)}
          sx={{ width: 110 }}
        />
        <TextField
          size="small"
          label="Телефон"
          value={form.phone}
          onChange={(e) => set('phone', e.target.value)}
          sx={{ width: 160 }}
        />
        <Button variant="contained" onClick={add} disabled={saving} sx={{ textTransform: 'none' }}>
          {saving ? 'Сохранение...' : 'Добавить звонок'}
        </Button>
      </Box>

      {isLoading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 3 }}>
          <CircularProgress size={28} />
        </Box>
      ) : calls.length === 0 ? (
        <Typography sx={{ color: 'text.secondary', fontSize: '0.85rem' }}>Звонков пока нет</Typography>
      ) : (
        <Stack spacing={1}>
          {calls.map((call) => (
            <Box
              key={call.id}
              sx={{
                display: 'flex',
                alignItems: 'center',
                gap: 1,
                p: 1,
                border: 1,
                borderColor: 'divider',
                borderRadius: 1,
              }}
            >
              {call.direction === 'inbound' ? (
                <InIcon fontSize="small" color="success" />
              ) : (
                <OutIcon fontSize="small" color="primary" />
              )}
              <Box sx={{ flex: 1, minWidth: 0 }}>
                <Typography sx={{ fontSize: '0.85rem', fontWeight: 600 }}>{call.subject}</Typography>
                <Typography sx={{ fontSize: '0.75rem', color: 'text.secondary' }}>
                  {call.direction === 'inbound' ? 'Входящий' : 'Исходящий'}
                  {call.outcome ? ` · ${OUTCOMES[call.outcome] ?? call.outcome}` : ''}
                  {call.duration_seconds ? ` · ${call.duration_seconds}с` : ''}
                  {call.phone ? ` · ${call.phone}` : ''}
                </Typography>
              </Box>
              <CallIcon fontSize="small" sx={{ color: 'text.disabled' }} />
            </Box>
          ))}
        </Stack>
      )}
    </Stack>
  )
}
