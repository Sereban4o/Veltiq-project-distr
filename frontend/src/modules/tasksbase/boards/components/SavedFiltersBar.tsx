import { useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Stack,
  TextField,
} from '@mui/material'
import { BookmarkAdd as SaveIcon, Delete as DeleteIcon } from '@mui/icons-material'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import type { SavedFilter } from '@/types'

interface Props {
  projectId?: number | null
  current: Record<string, unknown>
  onApply: (filters: Record<string, unknown>) => void
}

export default function SavedFiltersBar({ projectId, current, onApply }: Props) {
  const queryClient = useQueryClient()
  const [saveOpen, setSaveOpen] = useState(false)
  const [name, setName] = useState('')
  const [error, setError] = useState('')

  const { data: filters } = useQuery<SavedFilter[]>({
    queryKey: ['saved-filters'],
    queryFn: async () =>
      apiClient
        .get('/v1/tasksbase/filters', { params: projectId ? { project_id: projectId } : {} })
        .then((r) => r.data),
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['saved-filters'] })

  const createFilter = useMutation({
    mutationFn: () =>
      apiClient.post('/v1/tasksbase/filters', {
        name,
        filters: current,
        project_id: projectId ?? undefined,
      }),
    onSuccess: () => {
      setName('')
      setSaveOpen(false)
      setError('')
      invalidate()
    },
    onError: (err: any) => setError(err?.response?.data?.message || 'Не удалось сохранить фильтр'),
  })

  const removeFilter = useMutation({
    mutationFn: (id: number) => apiClient.delete(`/v1/tasksbase/filters/${id}`),
    onSuccess: invalidate,
  })

  return (
    <Box>
      <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
        {filters?.map((filter) => (
          <Chip
            key={filter.id}
            label={filter.name}
            size="small"
            onClick={() => onApply((filter.filters as Record<string, unknown>) || {})}
            onDelete={() => removeFilter.mutate(filter.id)}
            deleteIcon={<DeleteIcon />}
          />
        ))}
        <Button size="small" startIcon={<SaveIcon />} onClick={() => setSaveOpen(true)}>
          Сохранить фильтр
        </Button>
      </Stack>

      <Dialog open={saveOpen} onClose={() => setSaveOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Сохранить фильтр</DialogTitle>
        <DialogContent sx={{ pt: 2 }}>
          <TextField autoFocus fullWidth label="Название" value={name} onChange={(e) => setName(e.target.value)} />
          {error && (
            <Alert severity="error" sx={{ mt: 2 }} onClose={() => setError('')}>
              {error}
            </Alert>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setSaveOpen(false)}>Отмена</Button>
          <Button
            variant="contained"
            disabled={!name.trim() || createFilter.isPending}
            onClick={() => createFilter.mutate()}
          >
            Сохранить
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  )
}
