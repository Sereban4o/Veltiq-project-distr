import { useEffect, useMemo, useState } from 'react'
import { Alert, Box, Button, Divider, Paper, Stack, Typography } from '@mui/material'
import apiClient from '@/modules/shared/api/apiClient'
import LoadingSpinner from '@/modules/shared/components/LoadingSpinner'

interface Entry {
  id: number
  issue_key?: string | null
  summary?: string | null
  hours: number
  comment?: string | null
}

interface Day {
  date: string
  seconds: number
  entries: Entry[]
}

interface Timesheet {
  from: string
  to: string
  total_hours: number
  days: Day[]
}

function isoDate(date: Date): string {
  const pad = (value: number) => (value < 10 ? `0${value}` : String(value))
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

function weekBounds(offsetWeeks: number): { from: string; to: string } {
  const now = new Date()
  const day = (now.getDay() + 6) % 7
  const monday = new Date(now)
  monday.setDate(now.getDate() - day + offsetWeeks * 7)
  const sunday = new Date(monday)
  sunday.setDate(monday.getDate() + 6)
  return { from: isoDate(monday), to: isoDate(sunday) }
}

export default function TimesheetPage() {
  const [offset, setOffset] = useState(0)
  const { from, to } = useMemo(() => weekBounds(offset), [offset])
  const [data, setData] = useState<Timesheet | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    apiClient
      .get('/v1/tasksbase/timesheet', { params: { from, to } })
      .then((response) => {
        if (!cancelled) {
          setData(response.data?.data ?? null)
          setError('')
        }
      })
      .catch(() => {
        if (!cancelled) setError('Не удалось загрузить табель')
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [from, to])

  const exportCsv = async () => {
    const response = await apiClient.get('/v1/tasksbase/timesheet/export', {
      params: { from, to },
      responseType: 'blob',
    })
    const url = URL.createObjectURL(response.data as Blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `timesheet-${from}-${to}.csv`
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    URL.revokeObjectURL(url)
  }

  return (
    <Box sx={{ p: 3 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 2 }}>
        <Typography variant="h5">
          Табель: {from} — {to}
        </Typography>
        <Stack direction="row" spacing={1}>
          <Button onClick={() => setOffset((value) => value - 1)}>← Пред.</Button>
          <Button onClick={() => setOffset(0)}>Текущая неделя</Button>
          <Button onClick={() => setOffset((value) => value + 1)}>След. →</Button>
          <Button variant="contained" onClick={exportCsv}>
            Экспорт CSV
          </Button>
        </Stack>
      </Box>

      {error && <Alert severity="error">{error}</Alert>}

      {loading ? (
        <LoadingSpinner />
      ) : !data || data.days.length === 0 ? (
        <Typography color="text.secondary">За эту неделю списаний нет.</Typography>
      ) : (
        <>
          <Typography variant="subtitle1" sx={{ mb: 2 }}>
            Всего: <strong>{data.total_hours} ч</strong>
          </Typography>
          {data.days.map((day) => (
            <Paper key={day.date} sx={{ p: 2, mb: 2 }}>
              <Typography variant="subtitle2" sx={{ mb: 1 }}>
                {day.date} — {Math.round((day.seconds / 3600) * 100) / 100} ч
              </Typography>
              <Divider sx={{ mb: 1 }} />
              {day.entries.map((entry) => (
                <Box key={entry.id} sx={{ display: 'flex', justifyContent: 'space-between', py: 0.5 }}>
                  <Typography variant="body2">
                    <strong>{entry.issue_key}</strong> {entry.summary}
                    {entry.comment ? ` — ${entry.comment}` : ''}
                  </Typography>
                  <Typography variant="body2">{entry.hours} ч</Typography>
                </Box>
              ))}
            </Paper>
          ))}
        </>
      )}
    </Box>
  )
}
