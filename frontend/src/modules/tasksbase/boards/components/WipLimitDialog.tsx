import { useEffect, useState } from 'react'
import {
  Alert,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  Stack,
  Switch,
  TextField,
  Typography,
} from '@mui/material'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import type { BoardColumn } from '@/types'

interface Props {
  boardId: number
  columns: BoardColumn[]
  wipEnforcement: boolean
  open: boolean
  onClose: () => void
}

export default function WipLimitDialog({ boardId, columns, wipEnforcement, open, onClose }: Props) {
  const queryClient = useQueryClient()
  const [limits, setLimits] = useState<Record<number, string>>({})
  const [enforce, setEnforce] = useState(wipEnforcement)
  const [error, setError] = useState('')

  useEffect(() => {
    if (open) {
      setEnforce(wipEnforcement)
      setLimits(
        Object.fromEntries(
          columns.map((column) => [column.id, column.wip_limit != null ? String(column.wip_limit) : '']),
        ),
      )
      setError('')
    }
  }, [open, wipEnforcement, columns])

  const save = useMutation({
    mutationFn: async () => {
      await apiClient.patch(`/v1/tasksbase/boards/${boardId}`, { wip_enforcement: enforce })

      await Promise.all(
        columns.map((column) => {
          const raw = limits[column.id]
          const next = raw && raw.trim() !== '' ? Number(raw) : null
          if ((column.wip_limit ?? null) !== next) {
            return apiClient.patch(`/v1/tasksbase/boards/${boardId}/columns/${column.id}`, { wip_limit: next })
          }
          return Promise.resolve()
        }),
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['board-view', boardId] })
      onClose()
    },
    onError: (err: any) => setError(err?.response?.data?.message || 'Не удалось сохранить лимиты'),
  })

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Лимиты WIP</DialogTitle>
      <DialogContent sx={{ pt: 2 }}>
        <FormControlLabel
          control={<Switch checked={enforce} onChange={(e) => setEnforce(e.target.checked)} />}
          label="Ограничивать перемещение при превышении лимита"
        />

        <Stack spacing={2} sx={{ mt: 2 }}>
          {columns.map((column) => (
            <Stack key={column.id} direction="row" alignItems="center" spacing={2}>
              <Typography variant="body2" sx={{ flexGrow: 1 }}>
                {column.name}
              </Typography>
              <TextField
                size="small"
                type="number"
                label="Лимит"
                inputProps={{ min: 1 }}
                value={limits[column.id] ?? ''}
                onChange={(e) => setLimits((prev) => ({ ...prev, [column.id]: e.target.value }))}
                sx={{ width: 120 }}
              />
            </Stack>
          ))}
        </Stack>

        {error && (
          <Alert severity="error" sx={{ mt: 2 }} onClose={() => setError('')}>
            {error}
          </Alert>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Отмена</Button>
        <Button variant="contained" onClick={() => save.mutate()} disabled={save.isPending}>
          Сохранить
        </Button>
      </DialogActions>
    </Dialog>
  )
}
