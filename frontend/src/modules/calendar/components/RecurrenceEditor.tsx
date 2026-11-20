import { useEffect, useMemo, useRef, useState } from 'react'
import {
  Box,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  TextField,
  ToggleButton,
  ToggleButtonGroup,
  Typography,
} from '@mui/material'
import { describeRrule } from '../utils'

type Preset = 'none' | 'daily' | 'weekly' | 'monthly' | 'yearly' | 'custom'
type EndMode = 'never' | 'until' | 'count'

const WEEKDAYS = [
  { key: 'MO', label: 'Пн' },
  { key: 'TU', label: 'Вт' },
  { key: 'WE', label: 'Ср' },
  { key: 'TH', label: 'Чт' },
  { key: 'FR', label: 'Пт' },
  { key: 'SA', label: 'Сб' },
  { key: 'SU', label: 'Вс' },
]

interface Props {
  value: string | null
  onChange: (rrule: string | null) => void
}

function detectPreset(value: string | null): Preset {
  if (!value) return 'none'
  const freq = /FREQ=([A-Z]+)/.exec(value)?.[1]
  switch (freq) {
    case 'DAILY':
      return 'daily'
    case 'WEEKLY':
      return 'weekly'
    case 'MONTHLY':
      return 'monthly'
    case 'YEARLY':
      return 'yearly'
    default:
      return 'custom'
  }
}

export default function RecurrenceEditor({ value, onChange }: Props) {
  const [preset, setPreset] = useState<Preset>(() => detectPreset(value))
  const [interval, setInterval] = useState(1)
  const [weekdays, setWeekdays] = useState<string[]>(['MO'])
  const [endMode, setEndMode] = useState<EndMode>('never')
  const [until, setUntil] = useState('')
  const [count, setCount] = useState(10)
  const [raw, setRaw] = useState(value ?? '')
  const lastEmitted = useRef<string | null>(value ?? null)

  // Re-sync internal state only when the value changes externally (editing a
  // different event), not when we emitted it ourselves.
  useEffect(() => {
    if ((value ?? null) === lastEmitted.current) return
    lastEmitted.current = value ?? null
    setPreset(detectPreset(value))
    setRaw(value ?? '')

    if (value) {
      const intervalMatch = /INTERVAL=(\d+)/.exec(value)
      setInterval(intervalMatch ? Number(intervalMatch[1]) : 1)
      const byday = /BYDAY=([A-Z,]+)/.exec(value)?.[1]
      if (byday) setWeekdays(byday.split(','))
      const countMatch = /COUNT=(\d+)/.exec(value)
      const untilMatch = /UNTIL=(\d{8})/.exec(value)
      if (countMatch) {
        setEndMode('count')
        setCount(Number(countMatch[1]))
      } else if (untilMatch) {
        setEndMode('until')
        setUntil(`${untilMatch[1].slice(0, 4)}-${untilMatch[1].slice(4, 6)}-${untilMatch[1].slice(6, 8)}`)
      } else {
        setEndMode('never')
      }
    }
  }, [value])

  const generated = useMemo(() => {
    if (preset === 'none') return null
    if (preset === 'custom') return raw.trim() || null

    const parts = [`FREQ=${preset.toUpperCase()}`]
    if (interval > 1) parts.push(`INTERVAL=${interval}`)
    if (preset === 'weekly' && weekdays.length) parts.push(`BYDAY=${weekdays.join(',')}`)

    if (endMode === 'until' && until) {
      parts.push(`UNTIL=${until.replace(/-/g, '')}T235959Z`)
    } else if (endMode === 'count' && count > 0) {
      parts.push(`COUNT=${count}`)
    }

    return parts.join(';')
  }, [preset, interval, weekdays, endMode, until, count, raw])

  useEffect(() => {
    if (generated === lastEmitted.current) return
    lastEmitted.current = generated
    onChange(generated)
  }, [generated, onChange])

  return (
    <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1.5 }}>
      <FormControl fullWidth size="small">
        <InputLabel>Повторение</InputLabel>
        <Select label="Повторение" value={preset} onChange={(e) => setPreset(e.target.value as Preset)}>
          <MenuItem value="none">Не повторяется</MenuItem>
          <MenuItem value="daily">Ежедневно</MenuItem>
          <MenuItem value="weekly">Еженедельно</MenuItem>
          <MenuItem value="monthly">Ежемесячно</MenuItem>
          <MenuItem value="yearly">Ежегодно</MenuItem>
          <MenuItem value="custom">Другое (RRULE)</MenuItem>
        </Select>
      </FormControl>

      {preset !== 'none' && preset !== 'custom' && (
        <>
          <TextField
            size="small"
            type="number"
            label="Интервал"
            value={interval}
            inputProps={{ min: 1, max: 99 }}
            onChange={(e) => setInterval(Math.max(1, Number(e.target.value) || 1))}
          />

          {preset === 'weekly' && (
            <ToggleButtonGroup
              value={weekdays}
              onChange={(_e, next: string[]) => setWeekdays(next.length ? next : ['MO'])}
              size="small"
              sx={{ flexWrap: 'wrap' }}
            >
              {WEEKDAYS.map((day) => (
                <ToggleButton key={day.key} value={day.key} sx={{ px: 1.25, py: 0.5 }}>
                  {day.label}
                </ToggleButton>
              ))}
            </ToggleButtonGroup>
          )}

          <FormControl fullWidth size="small">
            <InputLabel>Окончание</InputLabel>
            <Select label="Окончание" value={endMode} onChange={(e) => setEndMode(e.target.value as EndMode)}>
              <MenuItem value="never">Никогда</MenuItem>
              <MenuItem value="until">До даты</MenuItem>
              <MenuItem value="count">После N раз</MenuItem>
            </Select>
          </FormControl>

          {endMode === 'until' && (
            <TextField
              size="small"
              type="date"
              label="До"
              value={until}
              InputLabelProps={{ shrink: true }}
              onChange={(e) => setUntil(e.target.value)}
            />
          )}

          {endMode === 'count' && (
            <TextField
              size="small"
              type="number"
              label="Количество"
              value={count}
              inputProps={{ min: 1, max: 1000 }}
              onChange={(e) => setCount(Math.max(1, Number(e.target.value) || 1))}
            />
          )}
        </>
      )}

      {preset === 'custom' && (
        <TextField
          size="small"
          label="RRULE"
          value={raw}
          placeholder="FREQ=WEEKLY;BYDAY=MO,WE"
          onChange={(e) => setRaw(e.target.value)}
        />
      )}

      {generated && (
        <Typography variant="caption" color="text.secondary">
          {describeRrule(generated)} ({generated})
        </Typography>
      )}
    </Box>
  )
}
