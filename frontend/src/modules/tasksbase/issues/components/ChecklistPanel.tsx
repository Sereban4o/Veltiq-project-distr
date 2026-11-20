import { useState } from 'react'
import {
  Box,
  Button,
  Card,
  CardContent,
  Checkbox,
  Chip,
  Divider,
  IconButton,
  LinearProgress,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import { Add as AddIcon, Delete as DeleteIcon } from '@mui/icons-material'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import type { IssueChecklist } from '@/types'

export default function ChecklistPanel({ issueId }: { issueId: string }) {
  const queryClient = useQueryClient()
  const [newTitle, setNewTitle] = useState('')
  const [drafts, setDrafts] = useState<Record<number, string>>({})

  const { data: checklists, isLoading } = useQuery<IssueChecklist[]>({
    queryKey: ['issue-checklists', issueId],
    queryFn: async () => apiClient.get(`/v1/tasksbase/issues/${issueId}/checklists`).then((r) => r.data),
    enabled: !!issueId,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['issue-checklists', issueId] })

  const createChecklist = useMutation({
    mutationFn: (title: string) => apiClient.post(`/v1/tasksbase/issues/${issueId}/checklists`, { title }),
    onSuccess: () => {
      setNewTitle('')
      invalidate()
    },
  })

  const deleteChecklist = useMutation({
    mutationFn: (checklistId: number) => apiClient.delete(`/v1/tasksbase/issues/${issueId}/checklists/${checklistId}`),
    onSuccess: invalidate,
  })

  const addItem = useMutation({
    mutationFn: ({ checklistId, body }: { checklistId: number; body: string }) =>
      apiClient.post(`/v1/tasksbase/issues/${issueId}/checklists/${checklistId}/items`, { body }),
    onSuccess: (_data, vars) => {
      setDrafts((prev) => ({ ...prev, [vars.checklistId]: '' }))
      invalidate()
    },
  })

  const toggleItem = useMutation({
    mutationFn: ({ checklistId, itemId, done }: { checklistId: number; itemId: number; done: boolean }) =>
      apiClient.patch(`/v1/tasksbase/issues/${issueId}/checklists/${checklistId}/items/${itemId}`, { done }),
    onSuccess: invalidate,
  })

  const deleteItem = useMutation({
    mutationFn: ({ checklistId, itemId }: { checklistId: number; itemId: number }) =>
      apiClient.delete(`/v1/tasksbase/issues/${issueId}/checklists/${checklistId}/items/${itemId}`),
    onSuccess: invalidate,
  })

  return (
    <Card>
      <CardContent>
        <Typography variant="h6" gutterBottom>
          Чек-листы
        </Typography>

        {isLoading && <LinearProgress />}

        {!isLoading && (checklists?.length ?? 0) === 0 && (
          <Typography variant="body2" color="text.secondary">
            Чек-листов пока нет
          </Typography>
        )}

        <Stack spacing={2} sx={{ mt: 1 }}>
          {checklists?.map((checklist) => {
            const progress = checklist.total ? (checklist.done_count / checklist.total) * 100 : 0

            return (
              <Box key={checklist.id}>
                <Stack direction="row" alignItems="center" spacing={1}>
                  <Typography variant="subtitle2" sx={{ flexGrow: 1 }}>
                    {checklist.title}
                  </Typography>
                  <Chip
                    size="small"
                    label={`${checklist.done_count}/${checklist.total}`}
                    color={checklist.total > 0 && checklist.done_count === checklist.total ? 'success' : 'default'}
                  />
                  <IconButton size="small" color="error" onClick={() => deleteChecklist.mutate(checklist.id)}>
                    <DeleteIcon fontSize="small" />
                  </IconButton>
                </Stack>

                {checklist.total > 0 && (
                  <LinearProgress variant="determinate" value={progress} sx={{ my: 1, borderRadius: 1 }} />
                )}

                <Stack spacing={0.5}>
                  {checklist.items.map((item) => (
                    <Stack key={item.id} direction="row" alignItems="center" spacing={0.5}>
                      <Checkbox
                        size="small"
                        checked={item.done}
                        onChange={(e) =>
                          toggleItem.mutate({
                            checklistId: checklist.id,
                            itemId: item.id,
                            done: e.target.checked,
                          })
                        }
                      />
                      <Typography
                        variant="body2"
                        sx={{
                          flexGrow: 1,
                          textDecoration: item.done ? 'line-through' : 'none',
                          color: item.done ? 'text.secondary' : 'text.primary',
                        }}
                      >
                        {item.body}
                      </Typography>
                      {item.assignee && <Chip size="small" label={item.assignee.full_name} />}
                      <IconButton
                        size="small"
                        onClick={() => deleteItem.mutate({ checklistId: checklist.id, itemId: item.id })}
                      >
                        <DeleteIcon fontSize="small" />
                      </IconButton>
                    </Stack>
                  ))}
                </Stack>

                <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
                  <TextField
                    size="small"
                    fullWidth
                    placeholder="Новый пункт"
                    value={drafts[checklist.id] ?? ''}
                    onChange={(e) => setDrafts((prev) => ({ ...prev, [checklist.id]: e.target.value }))}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        const body = (drafts[checklist.id] ?? '').trim()
                        if (body) addItem.mutate({ checklistId: checklist.id, body })
                      }
                    }}
                  />
                  <Button
                    size="small"
                    startIcon={<AddIcon />}
                    disabled={!(drafts[checklist.id] ?? '').trim() || addItem.isPending}
                    onClick={() => {
                      const body = (drafts[checklist.id] ?? '').trim()
                      if (body) addItem.mutate({ checklistId: checklist.id, body })
                    }}
                  >
                    Добавить
                  </Button>
                </Stack>

                <Divider sx={{ mt: 2 }} />
              </Box>
            )
          })}
        </Stack>

        <Stack direction="row" spacing={1} sx={{ mt: 2 }}>
          <TextField
            size="small"
            fullWidth
            placeholder="Название чек-листа"
            value={newTitle}
            onChange={(e) => setNewTitle(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && newTitle.trim()) createChecklist.mutate(newTitle.trim())
            }}
          />
          <Button
            variant="contained"
            startIcon={<AddIcon />}
            disabled={!newTitle.trim() || createChecklist.isPending}
            onClick={() => createChecklist.mutate(newTitle.trim())}
          >
            Чек-лист
          </Button>
        </Stack>
      </CardContent>
    </Card>
  )
}
