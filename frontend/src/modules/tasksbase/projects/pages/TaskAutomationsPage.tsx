import { useEffect, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  List,
  ListItem,
  ListItemText,
  MenuItem,
  Switch,
  TextField,
  Typography,
} from '@mui/material'
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline'
import apiClient from '@/modules/shared/api/apiClient'
import LoadingSpinner from '@/modules/shared/components/LoadingSpinner'

interface AutomationRule {
  id: number
  name: string
  trigger: string
  conditions?: Record<string, unknown> | null
  actions: Array<Record<string, any>>
  is_active: boolean
}

interface SimpleUser {
  id: number
  first_name?: string
  last_name?: string
}

const TRIGGERS = [
  { value: 'issue_created', label: 'Задача создана' },
  { value: 'status_changed', label: 'Смена статуса' },
]

const ACTION_TYPES = [
  { value: 'assign', label: 'Назначить исполнителя' },
  { value: 'set_priority', label: 'Установить приоритет' },
  { value: 'notify', label: 'Отправить уведомление' },
]

const PRIORITIES = ['lowest', 'low', 'medium', 'high', 'highest']

function describeAction(action: Record<string, any>): string {
  if (action.type === 'assign') return `Назначить (user #${action.user_id ?? '?'})`
  if (action.type === 'set_priority') return `Приоритет: ${action.priority ?? '?'}`
  if (action.type === 'notify') return `Уведомление: ${action.message ?? 'без текста'}`
  return String(action.type ?? '?')
}

export default function TaskAutomationsPage() {
  const [projectId, setProjectId] = useState<number | null>(null)
  const [rules, setRules] = useState<AutomationRule[]>([])
  const [users, setUsers] = useState<SimpleUser[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [open, setOpen] = useState(false)

  const [name, setName] = useState('')
  const [trigger, setTrigger] = useState('issue_created')
  const [actionType, setActionType] = useState('assign')
  const [actionUser, setActionUser] = useState('')
  const [actionPriority, setActionPriority] = useState('medium')
  const [actionMessage, setActionMessage] = useState('')

  const loadRules = async (id: number) => {
    const response = await apiClient.get('/v1/tasksbase/automations', { params: { project_id: id } })
    setRules(response.data?.data ?? [])
  }

  useEffect(() => {
    const load = async () => {
      setLoading(true)
      try {
        const projects = await apiClient.get('/v1/tasksbase/projects').then((r) => r.data.data ?? r.data)
        const id = projects?.[0]?.id
        if (!id) {
          setError('Проекты не найдены')
          return
        }
        setProjectId(id)
        await loadRules(id)

        const userList = await apiClient.get('/v1/users/all').then((r) => r.data.data ?? r.data)
        setUsers(Array.isArray(userList) ? userList : [])
      } catch {
        setError('Не удалось загрузить правила')
      } finally {
        setLoading(false)
      }
    }
    void load()
  }, [])

  const buildAction = (): Record<string, any> => {
    if (actionType === 'assign') return { type: 'assign', user_id: Number(actionUser) }
    if (actionType === 'set_priority') return { type: 'set_priority', priority: actionPriority }
    return { type: 'notify', user_id: actionUser ? Number(actionUser) : undefined, message: actionMessage }
  }

  const create = async () => {
    if (!projectId || !name.trim()) return
    try {
      await apiClient.post('/v1/tasksbase/automations', {
        name: name.trim(),
        project_id: projectId,
        trigger,
        actions: [buildAction()],
      })
      setOpen(false)
      setName('')
      await loadRules(projectId)
    } catch {
      setError('Не удалось создать правило')
    }
  }

  const toggle = async (rule: AutomationRule) => {
    await apiClient.patch(`/v1/tasksbase/automations/${rule.id}`, { is_active: !rule.is_active })
    if (projectId) await loadRules(projectId)
  }

  const remove = async (rule: AutomationRule) => {
    await apiClient.delete(`/v1/tasksbase/automations/${rule.id}`)
    if (projectId) await loadRules(projectId)
  }

  if (loading) return <LoadingSpinner />

  return (
    <Box sx={{ p: 3 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 2 }}>
        <Typography variant="h5">Автоматизации задач</Typography>
        <Button variant="contained" onClick={() => setOpen(true)} disabled={!projectId}>
          Добавить правило
        </Button>
      </Box>

      {error && (
        <Alert severity="error" sx={{ mb: 2 }}>
          {error}
        </Alert>
      )}

      {rules.length === 0 ? (
        <Typography color="text.secondary">Правил пока нет.</Typography>
      ) : (
        <List>
          {rules.map((rule) => (
            <ListItem
              key={rule.id}
              secondaryAction={
                <>
                  <Switch checked={rule.is_active} onChange={() => toggle(rule)} />
                  <IconButton edge="end" onClick={() => remove(rule)} aria-label="Удалить">
                    <DeleteOutlineIcon fontSize="small" />
                  </IconButton>
                </>
              }
            >
              <ListItemText
                primary={
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                    {rule.name}
                    <Chip size="small" label={TRIGGERS.find((t) => t.value === rule.trigger)?.label ?? rule.trigger} />
                  </Box>
                }
                secondary={(rule.actions ?? []).map(describeAction).join('; ')}
              />
            </ListItem>
          ))}
        </List>
      )}

      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="sm">
        <DialogTitle>Новое правило</DialogTitle>
        <DialogContent>
          <TextField
            fullWidth
            label="Название"
            value={name}
            onChange={(e) => setName(e.target.value)}
            sx={{ mt: 1, mb: 2 }}
          />
          <TextField
            fullWidth
            select
            label="Событие"
            value={trigger}
            onChange={(e) => setTrigger(e.target.value)}
            sx={{ mb: 2 }}
          >
            {TRIGGERS.map((t) => (
              <MenuItem key={t.value} value={t.value}>
                {t.label}
              </MenuItem>
            ))}
          </TextField>
          <TextField
            fullWidth
            select
            label="Действие"
            value={actionType}
            onChange={(e) => setActionType(e.target.value)}
            sx={{ mb: 2 }}
          >
            {ACTION_TYPES.map((a) => (
              <MenuItem key={a.value} value={a.value}>
                {a.label}
              </MenuItem>
            ))}
          </TextField>

          {(actionType === 'assign' || actionType === 'notify') && (
            <TextField
              fullWidth
              select
              label="Пользователь"
              value={actionUser}
              onChange={(e) => setActionUser(e.target.value)}
              sx={{ mb: 2 }}
            >
              {users.map((u) => (
                <MenuItem key={u.id} value={String(u.id)}>
                  {[u.first_name, u.last_name].filter(Boolean).join(' ') || `#${u.id}`}
                </MenuItem>
              ))}
            </TextField>
          )}

          {actionType === 'set_priority' && (
            <TextField
              fullWidth
              select
              label="Приоритет"
              value={actionPriority}
              onChange={(e) => setActionPriority(e.target.value)}
              sx={{ mb: 2 }}
            >
              {PRIORITIES.map((p) => (
                <MenuItem key={p} value={p}>
                  {p}
                </MenuItem>
              ))}
            </TextField>
          )}

          {actionType === 'notify' && (
            <TextField
              fullWidth
              label="Текст уведомления"
              value={actionMessage}
              onChange={(e) => setActionMessage(e.target.value)}
            />
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setOpen(false)}>Отмена</Button>
          <Button variant="contained" onClick={create} disabled={!name.trim()}>
            Создать
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  )
}
