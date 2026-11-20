import { useEffect, useMemo, useState } from 'react'
import {
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  Typography,
} from '@mui/material'
import { PlayArrow, Stop as StopIcon } from '@mui/icons-material'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ReferenceLine,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapList } from '@/modules/shared/utils/apiResponse'
import LoadingSpinner from '@/modules/shared/components/LoadingSpinner'

interface SprintListItem {
  id: number
  name: string
  status: string
  sequence: number
  start_date?: string | null
  end_date?: string | null
}

interface Metrics {
  sprint: { id: number; name: string; status: string; start_date?: string | null; end_date?: string | null }
  stats: { total: number; done: number; points_total: number; points_done: number }
  burndown: {
    total_points: number
    series: { date: string; ideal: number; remaining: number | null }[]
  }
  velocity: {
    average: number
    sprints: { sprint_id: number; name: string; sequence: number; completed_points: number }[]
  }
}

const formatDate = (value: string) => {
  const [, m, d] = value.split('-')
  return `${d}.${m}`
}

const STATUS_LABELS: Record<string, string> = {
  planning: 'Планирование',
  active: 'Активный',
  completed: 'Завершён',
  cancelled: 'Отменён',
}

export default function SprintPage() {
  const queryClient = useQueryClient()
  const [boardId, setBoardId] = useState<number | null>(null)
  const [sprintId, setSprintId] = useState<number | null>(null)
  const [carryoverId, setCarryoverId] = useState<number | ''>('')

  const { data: boards, isLoading: boardsLoading } = useQuery<SprintListItem[]>({
    queryKey: ['all-boards'],
    queryFn: async () => unwrapList(await apiClient.get('/v1/tasksbase/boards')) as any,
  })

  useEffect(() => {
    if (boards && boards.length > 0 && !boardId) {
      setBoardId((boards[0] as any).id)
    }
  }, [boards, boardId])

  const { data: sprints } = useQuery<SprintListItem[]>({
    queryKey: ['board-sprints', boardId],
    queryFn: async () => unwrapList(await apiClient.get(`/v1/tasksbase/boards/${boardId}/sprints`)) as any,
    enabled: !!boardId,
  })

  const activeSprints = useMemo(() => sprints ?? [], [sprints])

  useEffect(() => {
    if (activeSprints.length > 0 && !sprintId) {
      setSprintId(activeSprints[0].id)
    }
  }, [activeSprints, sprintId])

  const { data: metrics, isLoading: metricsLoading } = useQuery<Metrics>({
    queryKey: ['sprint-metrics', sprintId],
    queryFn: async () => (await apiClient.get(`/v1/tasksbase/sprints/${sprintId}/metrics`)).data,
    enabled: !!sprintId,
  })

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['sprint-metrics', sprintId] })
    queryClient.invalidateQueries({ queryKey: ['board-sprints', boardId] })
  }

  const startSprint = useMutation({
    mutationFn: () => apiClient.post(`/v1/tasksbase/sprints/${sprintId}/start`),
    onSuccess: invalidate,
  })

  const completeSprint = useMutation({
    mutationFn: () =>
      apiClient.post(`/v1/tasksbase/sprints/${sprintId}/complete`, {
        ...(carryoverId ? { carryover_sprint_id: Number(carryoverId) } : {}),
      }),
    onSuccess: () => {
      setCarryoverId('')
      invalidate()
    },
  })

  const burndownData = useMemo(
    () => (metrics?.burndown.series ?? []).map((point) => ({ ...point, dateLabel: formatDate(point.date) })),
    [metrics],
  )

  const velocityData = useMemo(
    () =>
      (metrics?.velocity.sprints ?? []).map((item) => ({ name: item.name, completed_points: item.completed_points })),
    [metrics],
  )

  if (boardsLoading) {
    return <LoadingSpinner message="Загрузка спринтов..." />
  }

  return (
    <Box>
      <Stack direction="row" spacing={2} alignItems="center" sx={{ mb: 3 }} flexWrap="wrap" useFlexGap>
        <Typography variant="h5">Спринты</Typography>

        <FormControl size="small" sx={{ minWidth: 200 }}>
          <InputLabel>Доска</InputLabel>
          <Select
            label="Доска"
            value={boardId ?? ''}
            onChange={(e) => {
              setBoardId(Number(e.target.value))
              setSprintId(null)
            }}
          >
            {(boards ?? []).map((board: any) => (
              <MenuItem key={board.id} value={board.id}>
                {board.name}
              </MenuItem>
            ))}
          </Select>
        </FormControl>

        <FormControl size="small" sx={{ minWidth: 200 }}>
          <InputLabel>Спринт</InputLabel>
          <Select label="Спринт" value={sprintId ?? ''} onChange={(e) => setSprintId(Number(e.target.value))}>
            {activeSprints.map((sprint) => (
              <MenuItem key={sprint.id} value={sprint.id}>
                {sprint.name} · {STATUS_LABELS[sprint.status] || sprint.status}
              </MenuItem>
            ))}
          </Select>
        </FormControl>
      </Stack>

      {activeSprints.length === 0 && <Typography color="text.secondary">На этой доске пока нет спринтов.</Typography>}

      {metricsLoading && <LoadingSpinner message="Расчёт метрик..." />}

      {metrics && (
        <>
          <Stack direction="row" spacing={1} sx={{ mb: 2 }} flexWrap="wrap" useFlexGap>
            <Chip label={`Задач: ${metrics.stats.total}`} />
            <Chip label={`Готово: ${metrics.stats.done}`} color="success" variant="outlined" />
            <Chip
              label={`Очков: ${metrics.stats.points_done}/${metrics.stats.points_total}`}
              color="primary"
              variant="outlined"
            />
            <Chip label={`Статус: ${STATUS_LABELS[metrics.sprint.status] || metrics.sprint.status}`} />
          </Stack>

          <Stack direction="row" spacing={1} sx={{ mb: 3 }} flexWrap="wrap" useFlexGap>
            <Button
              variant="contained"
              startIcon={<PlayArrow />}
              disabled={startSprint.isPending || metrics.sprint.status === 'active'}
              onClick={() => startSprint.mutate()}
            >
              Запустить спринт
            </Button>

            <FormControl size="small" sx={{ minWidth: 200 }}>
              <InputLabel>Перенести незавершённые</InputLabel>
              <Select
                label="Перенести незавершённые"
                value={carryoverId}
                onChange={(e) => setCarryoverId(e.target.value as number | '')}
              >
                <MenuItem value="">Не переносить</MenuItem>
                {activeSprints
                  .filter((sprint) => sprint.id !== sprintId && sprint.status !== 'completed')
                  .map((sprint) => (
                    <MenuItem key={sprint.id} value={sprint.id}>
                      {sprint.name}
                    </MenuItem>
                  ))}
              </Select>
            </FormControl>

            <Button
              variant="outlined"
              color="error"
              startIcon={<StopIcon />}
              disabled={completeSprint.isPending || metrics.sprint.status === 'completed'}
              onClick={() => completeSprint.mutate()}
            >
              Завершить спринт
            </Button>
          </Stack>

          <Card sx={{ mb: 3 }}>
            <CardContent>
              <Typography variant="h6" gutterBottom>
                Burndown
              </Typography>
              <Box sx={{ height: 320 }}>
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart data={burndownData} margin={{ top: 8, right: 24, bottom: 8, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="dateLabel" tick={{ fontSize: 12 }} />
                    <YAxis tick={{ fontSize: 12 }} />
                    <Tooltip />
                    <Legend />
                    <Line
                      type="monotone"
                      dataKey="ideal"
                      name="Идеально"
                      stroke="#9e9e9e"
                      strokeDasharray="5 5"
                      dot={false}
                    />
                    <Line
                      type="monotone"
                      dataKey="remaining"
                      name="Осталось очков"
                      stroke="#1976d2"
                      strokeWidth={2}
                      connectNulls
                    />
                  </LineChart>
                </ResponsiveContainer>
              </Box>
            </CardContent>
          </Card>

          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>
                Velocity (очков за спринт, среднее: {metrics.velocity.average})
              </Typography>
              <Box sx={{ height: 300 }}>
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={velocityData} margin={{ top: 8, right: 24, bottom: 8, left: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" />
                    <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                    <YAxis tick={{ fontSize: 12 }} />
                    <Tooltip />
                    <ReferenceLine
                      y={metrics.velocity.average}
                      stroke="#f44336"
                      strokeDasharray="4 4"
                      label="Среднее"
                    />
                    <Bar dataKey="completed_points" name="Завершено очков" fill="#4caf50" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </Box>
            </CardContent>
          </Card>
        </>
      )}
    </Box>
  )
}
