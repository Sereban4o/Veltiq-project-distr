import { useState } from 'react'
import {
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  IconButton,
  MenuItem,
  Stack,
  Switch,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Tooltip,
  Typography,
  Chip,
} from '@mui/material'
import { Add as AddIcon, Delete as DeleteIcon, Edit as EditIcon } from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { crmApi } from '../api'
import type { CrmAutomationRule, PipelineStage } from '../types'

const TRIGGERS: { value: CrmAutomationRule['trigger']; label: string }[] = [
  { value: 'deal_created', label: 'Сделка создана' },
  { value: 'stage_changed', label: 'Смена этапа' },
  { value: 'deal_won', label: 'Сделка выиграна' },
]

const ACTIONS: { value: CrmAutomationRule['action']; label: string }[] = [
  { value: 'create_task', label: 'Создать задачу' },
  { value: 'send_notification', label: 'Отправить уведомление' },
]

interface FormState {
  id?: number
  name: string
  trigger: CrmAutomationRule['trigger']
  action: CrmAutomationRule['action']
  isActive: boolean
  position: number
  condStage: string
  condStatus: string
  condMin: string
  condMax: string
  taskSubject: string
  dueInDays: string
  notifyTitle: string
  notifyBody: string
}

const emptyForm: FormState = {
  name: '',
  trigger: 'stage_changed',
  action: 'create_task',
  isActive: true,
  position: 0,
  condStage: '',
  condStatus: '',
  condMin: '',
  condMax: '',
  taskSubject: '',
  dueInDays: '1',
  notifyTitle: '',
  notifyBody: '',
}

function toForm(rule: CrmAutomationRule): FormState {
  const c = (rule.conditions ?? {}) as Record<string, unknown>
  const p = (rule.action_payload ?? {}) as Record<string, unknown>
  return {
    id: rule.id,
    name: rule.name,
    trigger: rule.trigger,
    action: rule.action,
    isActive: rule.is_active,
    position: rule.position,
    condStage: c.stage_id != null ? String(c.stage_id) : '',
    condStatus: c.status != null ? String(c.status) : '',
    condMin: c.min_amount != null ? String(c.min_amount) : '',
    condMax: c.max_amount != null ? String(c.max_amount) : '',
    taskSubject: p.subject != null ? String(p.subject) : '',
    dueInDays: p.due_in_days != null ? String(p.due_in_days) : '1',
    notifyTitle: p.title != null ? String(p.title) : '',
    notifyBody: p.body != null ? String(p.body) : '',
  }
}

export default function AutomationsTab() {
  const queryClient = useQueryClient()
  const [form, setForm] = useState<FormState | null>(null)
  const [saving, setSaving] = useState(false)

  const { data: rules = [], isLoading } = useQuery<CrmAutomationRule[]>({
    queryKey: ['crm-automation-rules'],
    queryFn: crmApi.automationRules,
  })
  const { data: stages = [] } = useQuery<PipelineStage[]>({ queryKey: ['crm-stages'], queryFn: crmApi.stages })

  const reload = () => queryClient.invalidateQueries({ queryKey: ['crm-automation-rules'] })

  const save = async () => {
    if (!form?.name.trim()) return
    setSaving(true)
    try {
      const conditions: Record<string, unknown> = {}
      if (form.condStage) conditions.stage_id = Number(form.condStage)
      if (form.condStatus) conditions.status = form.condStatus
      if (form.condMin) conditions.min_amount = Number(form.condMin)
      if (form.condMax) conditions.max_amount = Number(form.condMax)

      const action_payload: Record<string, unknown> =
        form.action === 'create_task'
          ? {
              subject: form.taskSubject || undefined,
              due_in_days: form.dueInDays ? Number(form.dueInDays) : undefined,
            }
          : {
              title: form.notifyTitle || undefined,
              body: form.notifyBody || undefined,
            }

      const payload = {
        name: form.name,
        trigger: form.trigger,
        action: form.action,
        conditions,
        action_payload,
        is_active: form.isActive,
        position: Number(form.position) || 0,
      }

      if (form.id) await crmApi.updateAutomationRule(form.id, payload)
      else await crmApi.createAutomationRule(payload)

      setForm(null)
      reload()
    } finally {
      setSaving(false)
    }
  }

  const remove = async (rule: CrmAutomationRule) => {
    if (!window.confirm(`Удалить правило «${rule.name}»?`)) return
    await crmApi.deleteAutomationRule(rule.id)
    reload()
  }

  const set = <K extends keyof FormState>(field: K, value: FormState[K]) =>
    setForm((prev) => (prev ? { ...prev, [field]: value } : prev))

  return (
    <Stack spacing={2}>
      <Box sx={{ display: 'flex', alignItems: 'center' }}>
        <Box sx={{ flex: 1 }}>
          <Typography sx={{ fontWeight: 700 }}>Автоматизации</Typography>
          <Typography sx={{ fontSize: '0.78rem', color: 'text.secondary' }}>
            Действия, выполняемые при событиях сделки
          </Typography>
        </Box>
        <Button
          variant="contained"
          startIcon={<AddIcon />}
          onClick={() => setForm({ ...emptyForm })}
          sx={{ textTransform: 'none' }}
        >
          Правило
        </Button>
      </Box>

      {isLoading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 4 }}>
          <CircularProgress />
        </Box>
      ) : (
        <Table size="small">
          <TableHead>
            <TableRow>
              <TableCell>Название</TableCell>
              <TableCell>Событие</TableCell>
              <TableCell>Действие</TableCell>
              <TableCell>Условие</TableCell>
              <TableCell align="center">Активно</TableCell>
              <TableCell align="right" />
            </TableRow>
          </TableHead>
          <TableBody>
            {rules.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} align="center" sx={{ color: 'text.secondary', py: 3 }}>
                  Правил пока нет
                </TableCell>
              </TableRow>
            )}
            {rules.map((rule) => (
              <TableRow key={rule.id} hover>
                <TableCell sx={{ fontSize: '0.85rem', fontWeight: 600 }}>{rule.name}</TableCell>
                <TableCell sx={{ fontSize: '0.83rem' }}>
                  {TRIGGERS.find((t) => t.value === rule.trigger)?.label ?? rule.trigger}
                </TableCell>
                <TableCell sx={{ fontSize: '0.83rem' }}>
                  {ACTIONS.find((a) => a.value === rule.action)?.label ?? rule.action}
                </TableCell>
                <TableCell sx={{ fontSize: '0.8rem', color: 'text.secondary' }}>
                  {rule.conditions && Object.keys(rule.conditions).length > 0
                    ? Object.entries(rule.conditions)
                        .map(([k, v]) => `${k}: ${v}`)
                        .join(', ')
                    : '—'}
                </TableCell>
                <TableCell align="center">
                  <Chip
                    size="small"
                    label={rule.is_active ? 'Да' : 'Нет'}
                    color={rule.is_active ? 'success' : 'default'}
                    variant="outlined"
                  />
                </TableCell>
                <TableCell align="right">
                  <Tooltip title="Редактировать">
                    <IconButton size="small" onClick={() => setForm(toForm(rule))}>
                      <EditIcon fontSize="small" />
                    </IconButton>
                  </Tooltip>
                  <Tooltip title="Удалить">
                    <IconButton size="small" onClick={() => remove(rule)}>
                      <DeleteIcon fontSize="small" />
                    </IconButton>
                  </Tooltip>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}

      <Dialog open={!!form} onClose={() => setForm(null)} fullWidth maxWidth="sm">
        <DialogTitle>{form?.id ? 'Правило автоматизации' : 'Новое правило'}</DialogTitle>
        <DialogContent>
          {form && (
            <Stack spacing={2} sx={{ mt: 1 }}>
              <TextField label="Название" value={form.name} onChange={(e) => set('name', e.target.value)} required />
              <TextField
                select
                label="Событие"
                value={form.trigger}
                onChange={(e) => set('trigger', e.target.value as FormState['trigger'])}
              >
                {TRIGGERS.map((t) => (
                  <MenuItem key={t.value} value={t.value}>
                    {t.label}
                  </MenuItem>
                ))}
              </TextField>
              <TextField
                select
                label="Действие"
                value={form.action}
                onChange={(e) => set('action', e.target.value as FormState['action'])}
              >
                {ACTIONS.map((a) => (
                  <MenuItem key={a.value} value={a.value}>
                    {a.label}
                  </MenuItem>
                ))}
              </TextField>

              <Typography sx={{ fontSize: '0.8rem', fontWeight: 600, color: 'text.secondary' }}>Условия</Typography>
              <Stack direction="row" spacing={2}>
                <TextField
                  select
                  label="Этап"
                  value={form.condStage}
                  onChange={(e) => set('condStage', e.target.value)}
                  sx={{ flex: 1 }}
                >
                  <MenuItem value="">Любой</MenuItem>
                  {stages.map((s) => (
                    <MenuItem key={s.id} value={s.id}>
                      {s.name}
                    </MenuItem>
                  ))}
                </TextField>
                <TextField
                  select
                  label="Статус"
                  value={form.condStatus}
                  onChange={(e) => set('condStatus', e.target.value)}
                  sx={{ flex: 1 }}
                >
                  <MenuItem value="">Любой</MenuItem>
                  <MenuItem value="open">Открыта</MenuItem>
                  <MenuItem value="won">Выиграна</MenuItem>
                  <MenuItem value="lost">Проиграна</MenuItem>
                </TextField>
              </Stack>
              <Stack direction="row" spacing={2}>
                <TextField
                  label="Сумма от"
                  type="number"
                  value={form.condMin}
                  onChange={(e) => set('condMin', e.target.value)}
                  sx={{ flex: 1 }}
                />
                <TextField
                  label="Сумма до"
                  type="number"
                  value={form.condMax}
                  onChange={(e) => set('condMax', e.target.value)}
                  sx={{ flex: 1 }}
                />
              </Stack>

              <Typography sx={{ fontSize: '0.8rem', fontWeight: 600, color: 'text.secondary' }}>
                Параметры действия
              </Typography>
              {form.action === 'create_task' ? (
                <>
                  <TextField
                    label="Тема задачи"
                    value={form.taskSubject}
                    onChange={(e) => set('taskSubject', e.target.value)}
                  />
                  <TextField
                    label="Срок, дней"
                    type="number"
                    value={form.dueInDays}
                    onChange={(e) => set('dueInDays', e.target.value)}
                  />
                </>
              ) : (
                <>
                  <TextField
                    label="Заголовок уведомления"
                    value={form.notifyTitle}
                    onChange={(e) => set('notifyTitle', e.target.value)}
                  />
                  <TextField
                    label="Текст"
                    value={form.notifyBody}
                    onChange={(e) => set('notifyBody', e.target.value)}
                    multiline
                    minRows={2}
                  />
                </>
              )}

              <Stack direction="row" spacing={2} alignItems="center">
                <FormControlLabel
                  control={<Switch checked={form.isActive} onChange={(e) => set('isActive', e.target.checked)} />}
                  label="Активно"
                />
                <TextField
                  label="Порядок"
                  type="number"
                  value={form.position}
                  onChange={(e) => set('position', Number(e.target.value))}
                  sx={{ width: 120 }}
                />
              </Stack>
            </Stack>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setForm(null)} sx={{ textTransform: 'none' }}>
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
