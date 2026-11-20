import { useState } from 'react'
import { Button, Card, CardContent, Chip, Divider, LinearProgress, Stack, TextField, Typography } from '@mui/material'
import { Add as AddIcon } from '@mui/icons-material'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link as RouterLink } from 'react-router-dom'
import apiClient from '@/modules/shared/api/apiClient'
import type { IssueSubtask } from '@/types'

export default function SubtasksPanel({ issueId }: { issueId: string }) {
  const queryClient = useQueryClient()
  const [summary, setSummary] = useState('')

  const { data: subtasks, isLoading } = useQuery<IssueSubtask[]>({
    queryKey: ['issue-subtasks', issueId],
    queryFn: async () => apiClient.get(`/v1/tasksbase/issues/${issueId}/subtasks`).then((r) => r.data),
    enabled: !!issueId,
  })

  const createSubtask = useMutation({
    mutationFn: (value: string) => apiClient.post(`/v1/tasksbase/issues/${issueId}/subtasks`, { summary: value }),
    onSuccess: () => {
      setSummary('')
      queryClient.invalidateQueries({ queryKey: ['issue-subtasks', issueId] })
    },
  })

  return (
    <Card>
      <CardContent>
        <Typography variant="h6" gutterBottom>
          Подзадачи
        </Typography>

        {isLoading && <LinearProgress />}

        {!isLoading && (subtasks?.length ?? 0) === 0 && (
          <Typography variant="body2" color="text.secondary">
            Подзадач пока нет
          </Typography>
        )}

        <Stack divider={<Divider flexItem />} spacing={1} sx={{ my: 1 }}>
          {subtasks?.map((subtask) => (
            <Stack key={subtask.id} direction="row" alignItems="center" spacing={1}>
              <Typography
                component={RouterLink}
                to={`/issues/${subtask.id}`}
                variant="body2"
                sx={{ flexGrow: 1, textDecoration: 'none', color: 'primary.main' }}
              >
                {subtask.key} · {subtask.summary}
              </Typography>
              {subtask.status && <Chip size="small" label={subtask.status.name} />}
              {subtask.assignee && <Chip size="small" label={subtask.assignee.full_name} />}
            </Stack>
          ))}
        </Stack>

        <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
          <TextField
            size="small"
            fullWidth
            placeholder="Название подзадачи"
            value={summary}
            onChange={(e) => setSummary(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && summary.trim()) createSubtask.mutate(summary.trim())
            }}
          />
          <Button
            variant="contained"
            startIcon={<AddIcon />}
            disabled={!summary.trim() || createSubtask.isPending}
            onClick={() => createSubtask.mutate(summary.trim())}
          >
            Добавить
          </Button>
        </Stack>
      </CardContent>
    </Card>
  )
}
