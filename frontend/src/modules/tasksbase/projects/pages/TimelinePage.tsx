import { useEffect, useState } from 'react'
import { Alert, Box, Chip, Paper, Tooltip, Typography } from '@mui/material'
import apiClient from '@/modules/shared/api/apiClient'
import LoadingSpinner from '@/modules/shared/components/LoadingSpinner'

interface TimelineIssue {
  id: number
  key: string
  summary: string
  priority?: string | null
  start_date?: string | null
  due_date?: string | null
  status?: { name: string; color?: string | null } | null
  assignee?: { name: string } | null
  epic?: { key: string; summary: string } | null
  blocked_by_count: number
}

const DAY_MS = 86_400_000

function toTime(value?: string | null): number | null {
  if (!value) return null
  const time = new Date(value).getTime()
  return Number.isNaN(time) ? null : time
}

export default function TimelinePage() {
  const [issues, setIssues] = useState<TimelineIssue[]>([])
  const [projectName, setProjectName] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false

    const load = async () => {
      setLoading(true)
      try {
        const projects = await apiClient.get('/v1/tasksbase/projects').then((r) => r.data.data ?? r.data)
        const key = projects?.[0]?.key
        if (!key) {
          if (!cancelled) {
            setIssues([])
            setError('Проекты не найдены')
          }
          return
        }

        const response = await apiClient.get(`/v1/tasksbase/projects/${key}/timeline`)
        if (cancelled) return
        const payload = response.data?.data ?? response.data
        setIssues(payload?.issues ?? [])
        setProjectName(payload?.project?.name ?? key)
        setError('')
      } catch {
        if (!cancelled) setError('Не удалось загрузить дорожную карту')
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    void load()
    return () => {
      cancelled = true
    }
  }, [])

  if (loading) return <LoadingSpinner />

  const times = issues
    .flatMap((issue) => [toTime(issue.start_date), toTime(issue.due_date)])
    .filter((value): value is number => value !== null)

  const min = times.length ? Math.min(...times) : Date.now()
  const max = times.length ? Math.max(...times) : Date.now()
  const span = Math.max(max - min, DAY_MS)

  return (
    <Box sx={{ p: 3 }}>
      <Typography variant="h5" sx={{ mb: 2 }}>
        Дорожная карта{projectName ? ` — ${projectName}` : ''}
      </Typography>

      {error && <Alert severity="error">{error}</Alert>}

      {!error && issues.length === 0 && (
        <Typography color="text.secondary">Нет задач с датами начала/окончания.</Typography>
      )}

      {!error && issues.length > 0 && (
        <Paper sx={{ p: 2 }}>
          {issues.map((issue) => {
            const start = toTime(issue.start_date) ?? toTime(issue.due_date) ?? min
            const end = toTime(issue.due_date) ?? start + DAY_MS
            const left = ((start - min) / span) * 100
            const width = Math.max(((end - start) / span) * 100, 1)
            const color = issue.status?.color || '#4C9AFF'

            return (
              <Box key={issue.id} sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 1 }}>
                <Box sx={{ width: 260, flexShrink: 0 }}>
                  <Typography variant="body2" noWrap title={issue.summary}>
                    <strong>{issue.key}</strong> {issue.summary}
                  </Typography>
                </Box>
                <Box sx={{ position: 'relative', flex: 1, height: 26, bgcolor: 'grey.100', borderRadius: 1 }}>
                  <Tooltip
                    title={[
                      `${issue.key} · ${issue.summary}`,
                      issue.start_date ? `Начало: ${issue.start_date}` : null,
                      issue.due_date ? `Срок: ${issue.due_date}` : null,
                      issue.assignee?.name ? `Исполнитель: ${issue.assignee.name}` : null,
                      issue.status?.name ? `Статус: ${issue.status.name}` : null,
                    ]
                      .filter(Boolean)
                      .join('\n')}
                  >
                    <Box
                      sx={{
                        position: 'absolute',
                        left: `${left}%`,
                        width: `${width}%`,
                        top: 3,
                        bottom: 3,
                        bgcolor: color,
                        borderRadius: 1,
                      }}
                    />
                  </Tooltip>
                </Box>
                <Box sx={{ width: 90, flexShrink: 0, textAlign: 'right' }}>
                  {issue.blocked_by_count > 0 && (
                    <Chip size="small" color="warning" label={`блок. ${issue.blocked_by_count}`} />
                  )}
                </Box>
              </Box>
            )
          })}
        </Paper>
      )}
    </Box>
  )
}
