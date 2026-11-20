import { FormControl, InputLabel, MenuItem, Select } from '@mui/material'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'

const SWIMLANE_OPTIONS = [
  { value: 'none', label: 'Без дорожек' },
  { value: 'assignee', label: 'По исполнителю' },
  { value: 'priority', label: 'По приоритету' },
  { value: 'epic', label: 'По эпику' },
] as const

export type SwimlaneBy = (typeof SWIMLANE_OPTIONS)[number]['value']

interface Props {
  boardId: number
  value: SwimlaneBy
}

export default function SwimlaneSelect({ boardId, value }: Props) {
  const queryClient = useQueryClient()

  const updateBoard = useMutation({
    mutationFn: (swimlaneBy: SwimlaneBy) =>
      apiClient.patch(`/v1/tasksbase/boards/${boardId}`, { swimlane_by: swimlaneBy }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['board-view', boardId] }),
  })

  return (
    <FormControl size="small" sx={{ minWidth: 180 }}>
      <InputLabel>Дорожки</InputLabel>
      <Select
        label="Дорожки"
        value={value}
        onChange={(e) => updateBoard.mutate(e.target.value as SwimlaneBy)}
        disabled={updateBoard.isPending}
      >
        {SWIMLANE_OPTIONS.map((option) => (
          <MenuItem key={option.value} value={option.value}>
            {option.label}
          </MenuItem>
        ))}
      </Select>
    </FormControl>
  )
}
