import { useState } from 'react'
import {
  Box,
  Button,
  Checkbox,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  MenuItem,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  ToggleButton,
  ToggleButtonGroup,
  Tooltip,
  Typography,
} from '@mui/material'
import { Add as AddIcon, Delete as DeleteIcon, Edit as EditIcon } from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { crmApi } from '../api'
import type { CrmActivity } from '../types'

const empty: Partial<CrmActivity> = {
  type: 'task',
  subject: '',
  description: '',
  due_at: null,
  is_completed: false,
  deal_id: null,
  contact_id: null,
  company_id: null,
}

const TYPE_LABEL: Record<string, string> = {
  call: 'Звонок',
  meeting: 'Встреча',
  task: 'Задача',
  email: 'Письмо',
  note: 'Заметка',
}

export default function ActivitiesTab() {
  const queryClient = useQueryClient()
  const [filter, setFilter] = useState('open')
  const [dialog, setDialog] = useState<Partial<CrmActivity> | null>(null)
  const [saving, setSaving] = useState(false)

  const { data = [], isLoading } = useQuery<CrmActivity[]>({
    queryKey: ['crm-activities', filter],
    queryFn: () => crmApi.activities({ filter: filter === 'all' ? undefined : filter }),
  })

  const { data: deals = [] } = useQuery({ queryKey: ['crm-deals'], queryFn: () => crmApi.deals() })

  const reload = () => {
    queryClient.invalidateQueries({ queryKey: ['crm-activities'] })
    queryClient.invalidateQueries({ queryKey: ['crm-dashboard'] })
  }

  const save = async () => {
    if (!dialog?.subject?.trim()) return
    setSaving(true)
    try {
      if (dialog.id) await crmApi.updateActivity(dialog.id, dialog)
      else await crmApi.createActivity(dialog)
      setDialog(null)
      reload()
    } finally {
      setSaving(false)
    }
  }

  const toggle = async (activity: CrmActivity) => {
    await crmApi.toggleActivity(activity.id)
    reload()
  }

  const remove = async (activity: CrmActivity) => {
    if (!window.confirm('Удалить задачу?')) return
    await crmApi.deleteActivity(activity.id)
    reload()
  }

  const set = (field: keyof CrmActivity, value: unknown) => setDialog((prev) => ({ ...prev, [field]: value }))

  return (
    <Stack spacing={2}>
      <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
        <ToggleButtonGroup size="small" exclusive value={filter} onChange={(_, v) => v && setFilter(v)}>
          <ToggleButton value="open">Открытые</ToggleButton>
          <ToggleButton value="today">Сегодня</ToggleButton>
          <ToggleButton value="overdue">Просроченные</ToggleButton>
          <ToggleButton value="completed">Выполненные</ToggleButton>
          <ToggleButton value="all">Все</ToggleButton>
        </ToggleButtonGroup>
        <Box sx={{ flex: 1 }} />
        <Button
          variant="contained"
          startIcon={<AddIcon />}
          onClick={() => setDialog({ ...empty })}
          sx={{ textTransform: 'none' }}
        >
          Задача
        </Button>
      </Box>

      {isLoading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 5 }}>
          <CircularProgress />
        </Box>
      ) : (
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell padding="checkbox" />
              <TableCell>Задача</TableCell>
              <TableCell>Тип</TableCell>
              <TableCell>Связано</TableCell>
              <TableCell>Срок</TableCell>
              <TableCell align="right" />
            </TableRow>
          </TableHead>
          <TableBody>
            {data.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} align="center" sx={{ color: 'text.secondary', py: 3 }}>
                  Задач нет
                </TableCell>
              </TableRow>
            )}
            {data.map((activity) => {
              const overdue = !activity.is_completed && activity.due_at && new Date(activity.due_at) < new Date()
              return (
                <TableRow key={activity.id} hover>
                  <TableCell padding="checkbox">
                    <Checkbox checked={activity.is_completed} onChange={() => toggle(activity)} />
                  </TableCell>
                  <TableCell>
                    <Typography
                      sx={{
                        fontSize: '0.88rem',
                        fontWeight: 600,
                        textDecoration: activity.is_completed ? 'line-through' : 'none',
                      }}
                    >
                      {activity.subject}
                    </Typography>
                    {activity.description && (
                      <Typography sx={{ fontSize: '0.78rem', color: 'text.secondary' }}>
                        {activity.description}
                      </Typography>
                    )}
                  </TableCell>
                  <TableCell>
                    <Chip size="small" label={TYPE_LABEL[activity.type] || activity.type} />
                  </TableCell>
                  <TableCell sx={{ fontSize: '0.83rem', color: 'text.secondary' }}>
                    {activity.deal?.title || activity.contact?.first_name || activity.company?.name || '—'}
                  </TableCell>
                  <TableCell sx={{ fontSize: '0.83rem', color: overdue ? 'error.main' : 'text.secondary' }}>
                    {activity.due_at
                      ? new Date(activity.due_at).toLocaleString('ru-RU', {
                          day: '2-digit',
                          month: '2-digit',
                          hour: '2-digit',
                          minute: '2-digit',
                        })
                      : '—'}
                  </TableCell>
                  <TableCell align="right">
                    <Tooltip title="Редактировать">
                      <IconButton size="small" onClick={() => setDialog({ ...activity })}>
                        <EditIcon fontSize="small" />
                      </IconButton>
                    </Tooltip>
                    <Tooltip title="Удалить">
                      <IconButton size="small" onClick={() => remove(activity)}>
                        <DeleteIcon fontSize="small" />
                      </IconButton>
                    </Tooltip>
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      )}

      <Dialog open={!!dialog} onClose={() => setDialog(null)} fullWidth maxWidth="sm">
        <DialogTitle>{dialog?.id ? 'Задача' : 'Новая задача'}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField
              label="Название"
              value={dialog?.subject ?? ''}
              onChange={(e) => set('subject', e.target.value)}
              required
            />
            <TextField label="Тип" select value={dialog?.type ?? 'task'} onChange={(e) => set('type', e.target.value)}>
              {Object.entries(TYPE_LABEL).map(([value, label]) => (
                <MenuItem key={value} value={value}>
                  {label}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              label="Сделка"
              select
              value={dialog?.deal_id ?? ''}
              onChange={(e) => set('deal_id', e.target.value ? Number(e.target.value) : null)}
            >
              <MenuItem value="">—</MenuItem>
              {deals.map((d) => (
                <MenuItem key={d.id} value={d.id}>
                  {d.title}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              label="Срок"
              type="datetime-local"
              value={dialog?.due_at ? String(dialog.due_at).slice(0, 16) : ''}
              onChange={(e) => set('due_at', e.target.value || null)}
              InputLabelProps={{ shrink: true }}
            />
            <TextField
              label="Описание"
              value={dialog?.description ?? ''}
              onChange={(e) => set('description', e.target.value)}
              multiline
              minRows={3}
            />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialog(null)} sx={{ textTransform: 'none' }}>
            Отмена
          </Button>
          <Button variant="contained" onClick={save} disabled={saving} sx={{ textTransform: 'none' }}>
            {saving ? 'Сохранение...' : 'Сохранить'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}
