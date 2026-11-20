import { useEffect, useState } from 'react'
import {
  Alert,
  Button,
  Card,
  CardContent,
  Chip,
  Divider,
  IconButton,
  LinearProgress,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import { Delete as DeleteIcon, PlayArrow, Stop as StopIcon } from '@mui/icons-material'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import type { IssueWorklogsResponse } from '@/types'

const formatDuration = (seconds: number): string => {
  const total = Math.max(0, Math.floor(seconds))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  return [h, m, s].map((part) => String(part).padStart(2, '0')).join(':')
}

export default function WorklogPanel({ issueId }: { issueId: string }) {
  const queryClient = useQueryClient()
  const [hours, setHours] = useState('')
  const [comment, setComment] = useState('')
  const [error, setError] = useState('')
  const [elapsed, setElapsed] = useState(0)

  const { data, isLoading } = useQuery<IssueWorklogsResponse>({
    queryKey: ['issue-worklogs', issueId],
    queryFn: async () => apiClient.get(`/v1/tasksbase/issues/${issueId}/worklogs`).then((r) => r.data),
    enabled: !!issueId,
    refetchInterval: 30000,
  })

  const running = data?.running ?? null

  useEffect(() => {
    if (!running?.started_at) {
      setElapsed(0)
      return
    }

    const startedAt = new Date(running.started_at).getTime()
    const tick = () => setElapsed(Math.max(0, Math.floor((Date.now() - startedAt) / 1000)))
    tick()
    const timer = window.setInterval(tick, 1000)
    return () => window.clearInterval(timer)
  }, [running?.id, running?.started_at])

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['issue-worklogs', issueId] })
    queryClient.invalidateQueries({ queryKey: ['issue', issueId] })
  }

  const startTimer = useMutation({
    mutationFn: () => apiClient.post(`/v1/tasksbase/issues/${issueId}/timer/start`),
    onSuccess: () => {
      setError('')
      invalidate()
    },
    onError: (err: any) => setError(err?.response?.data?.message || 'Не удалось запустить таймер'),
  })

  const stopTimer = useMutation({
    mutationFn: () => apiClient.post(`/v1/tasksbase/issues/${issueId}/timer/stop`),
    onSuccess: () => {
      setError('')
      invalidate()
    },
    onError: (err: any) => setError(err?.response?.data?.message || 'Не удалось остановить таймер'),
  })

  const addWorklog = useMutation({
    mutationFn: () =>
      apiClient.post(`/v1/tasksbase/issues/${issueId}/worklogs`, {
        hours: Number(hours),
        comment: comment || undefined,
      }),
    onSuccess: () => {
      setHours('')
      setComment('')
      setError('')
      invalidate()
    },
    onError: (err: any) => setError(err?.response?.data?.message || 'Не удалось добавить запись'),
  })

  const removeWorklog = useMutation({
    mutationFn: (worklogId: number) => apiClient.delete(`/v1/tasksbase/issues/${issueId}/worklogs/${worklogId}`),
    onSuccess: invalidate,
  })

  const totalHours = ((data?.total_seconds ?? 0) + (running ? elapsed : 0)) / 3600

  return (
    <Card>
      <CardContent>
        <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 1 }}>
          <Typography variant="h6" sx={{ flexGrow: 1 }}>
            Учёт времени
          </Typography>
          <Chip size="small" label={`${totalHours.toFixed(2)} ч`} />
        </Stack>

        {isLoading && <LinearProgress />}

        {error && (
          <Alert severity="error" sx={{ mb: 1 }} onClose={() => setError('')}>
            {error}
          </Alert>
        )}

        <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 2 }}>
          <Typography variant="body2" sx={{ fontFamily: 'monospace' }}>
            {formatDuration((running?.seconds ?? 0) + elapsed)}
          </Typography>
          {running ? (
            <Button
              size="small"
              color="error"
              variant="contained"
              startIcon={<StopIcon />}
              onClick={() => stopTimer.mutate()}
              disabled={stopTimer.isPending}
            >
              Стоп
            </Button>
          ) : (
            <Button
              size="small"
              variant="contained"
              startIcon={<PlayArrow />}
              onClick={() => startTimer.mutate()}
              disabled={startTimer.isPending}
            >
              Старт
            </Button>
          )}
        </Stack>

        <Stack divider={<Divider flexItem />} spacing={1} sx={{ mb: 2 }}>
          {(data?.data ?? []).map((worklog) => (
            <Stack key={worklog.id} direction="row" alignItems="center" spacing={1}>
              <Typography variant="body2" sx={{ minWidth: 60 }}>
                {worklog.hours.toFixed(2)} ч
              </Typography>
              <Typography variant="body2" sx={{ flexGrow: 1 }} color="text.secondary">
                {worklog.comment || worklog.user?.full_name || '—'}
              </Typography>
              {worklog.is_running && <Chip size="small" color="success" label="идёт" />}
              <Typography variant="caption" color="text.secondary">
                {worklog.started_at ? new Date(worklog.started_at).toLocaleDateString() : ''}
              </Typography>
              <IconButton size="small" onClick={() => removeWorklog.mutate(worklog.id)}>
                <DeleteIcon fontSize="small" />
              </IconButton>
            </Stack>
          ))}
        </Stack>

        <Stack direction="row" spacing={1}>
          <TextField
            size="small"
            type="number"
            label="Часы"
            inputProps={{ step: 0.25, min: 0.01 }}
            value={hours}
            onChange={(e) => setHours(e.target.value)}
            sx={{ width: 120 }}
          />
          <TextField
            size="small"
            fullWidth
            label="Комментарий"
            value={comment}
            onChange={(e) => setComment(e.target.value)}
          />
          <Button
            variant="contained"
            disabled={!hours || Number(hours) <= 0 || addWorklog.isPending}
            onClick={() => addWorklog.mutate()}
          >
            Добавить
          </Button>
        </Stack>
      </CardContent>
    </Card>
  )
}
