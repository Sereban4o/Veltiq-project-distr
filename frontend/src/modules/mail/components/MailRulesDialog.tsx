import { useState } from 'react'
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  List,
  ListItem,
  ListItemText,
  Switch,
  Tooltip,
  Typography,
} from '@mui/material'
import {
  Add as AddIcon,
  ArrowDownward as DownIcon,
  ArrowUpward as UpIcon,
  Delete as DeleteIcon,
  Edit as EditIcon,
} from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapList } from '@/modules/shared/utils/apiResponse'
import type { MailFilterRule, MailFolderRecord } from '../types'
import MailRuleEditor from './MailRuleEditor'

interface MailRulesDialogProps {
  open: boolean
  folders: MailFolderRecord[]
  onClose: () => void
}

function describe(rule: MailFilterRule): string {
  const groups = rule.conditions?.groups ?? []
  return groups
    .map((g) => {
      const conds = (g.conditions ?? [])
        .map((c) => `${c.field} ${c.op} ${c.value ?? ''}`.trim())
        .join(g.match === 'any' ? ' ИЛИ ' : ' И ')
      return `(${conds})`
    })
    .join(rule.conditions?.match === 'any' ? ' ИЛИ ' : ' И ')
}

export default function MailRulesDialog({ open, folders, onClose }: MailRulesDialogProps) {
  const queryClient = useQueryClient()
  const [editorOpen, setEditorOpen] = useState(false)
  const [editRule, setEditRule] = useState<MailFilterRule | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const { data: rules = [], isLoading } = useQuery<MailFilterRule[]>({
    queryKey: ['mail-rules'],
    queryFn: async () => unwrapList<MailFilterRule>(await apiClient.get('/v1/mail/rules')),
    enabled: open,
  })

  const reload = () => queryClient.invalidateQueries({ queryKey: ['mail-rules'] })

  const toggleActive = async (rule: MailFilterRule) => {
    await apiClient.patch(`/v1/mail/rules/${rule.id}`, { is_active: !rule.is_active })
    reload()
  }

  const remove = async (rule: MailFilterRule) => {
    if (!window.confirm(`Удалить правило «${rule.name}»?`)) return
    await apiClient.delete(`/v1/mail/rules/${rule.id}`)
    reload()
  }

  const move = async (index: number, direction: -1 | 1) => {
    const target = index + direction
    if (target < 0 || target >= rules.length) return
    const ids = rules.map((r) => r.id)
    ;[ids[index], ids[target]] = [ids[target], ids[index]]
    await apiClient.post('/v1/mail/rules/reorder', { ids })
    reload()
  }

  const applyRules = async () => {
    setNotice(null)
    setBusy(true)
    try {
      const res = await apiClient.post('/v1/mail/rules/apply')
      setNotice(`Перемещено писем: ${res.data?.moved ?? 0}`)
      queryClient.invalidateQueries({ queryKey: ['mail-messages'] })
      queryClient.invalidateQueries({ queryKey: ['mail-folders'] })
    } catch (e: any) {
      setNotice(e?.response?.data?.message || 'Не удалось применить правила')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle>Правила сортировки</DialogTitle>
      <DialogContent>
        {notice && (
          <Alert severity="success" sx={{ mb: 2 }} onClose={() => setNotice(null)}>
            {notice}
          </Alert>
        )}

        <Box sx={{ display: 'flex', gap: 1, mb: 1, flexWrap: 'wrap' }}>
          <Button
            variant="contained"
            startIcon={<AddIcon />}
            onClick={() => {
              setEditRule(null)
              setEditorOpen(true)
            }}
            disabled={folders.length === 0}
            sx={{ textTransform: 'none' }}
          >
            Добавить правило
          </Button>
          <Button onClick={applyRules} disabled={busy || rules.length === 0} sx={{ textTransform: 'none' }}>
            {busy ? 'Применение...' : 'Применить к существующим'}
          </Button>
        </Box>

        {folders.length === 0 && (
          <Alert severity="info" sx={{ mb: 1 }}>
            Сначала создайте папку, затем правило.
          </Alert>
        )}

        {isLoading ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', py: 3 }}>
            <CircularProgress size={24} />
          </Box>
        ) : rules.length === 0 ? (
          <Typography sx={{ color: 'text.secondary', py: 2 }}>Правил пока нет</Typography>
        ) : (
          <List dense>
            {rules.map((rule, index) => (
              <ListItem
                key={rule.id}
                divider
                secondaryAction={
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
                    <Switch size="small" checked={rule.is_active} onChange={() => toggleActive(rule)} />
                    <Tooltip title="Выше">
                      <span>
                        <IconButton size="small" disabled={index === 0} onClick={() => move(index, -1)}>
                          <UpIcon fontSize="small" />
                        </IconButton>
                      </span>
                    </Tooltip>
                    <Tooltip title="Ниже">
                      <span>
                        <IconButton size="small" disabled={index === rules.length - 1} onClick={() => move(index, 1)}>
                          <DownIcon fontSize="small" />
                        </IconButton>
                      </span>
                    </Tooltip>
                    <Tooltip title="Изменить">
                      <IconButton
                        size="small"
                        onClick={() => {
                          setEditRule(rule)
                          setEditorOpen(true)
                        }}
                      >
                        <EditIcon fontSize="small" />
                      </IconButton>
                    </Tooltip>
                    <Tooltip title="Удалить">
                      <IconButton size="small" onClick={() => remove(rule)}>
                        <DeleteIcon fontSize="small" />
                      </IconButton>
                    </Tooltip>
                  </Box>
                }
              >
                <ListItemText
                  primary={
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                      <Typography sx={{ fontWeight: 600 }}>{rule.name}</Typography>
                      <Typography component="span" sx={{ fontSize: '0.75rem', color: 'text.secondary' }}>
                        → {rule.target_folder?.name ?? '—'}
                      </Typography>
                    </Box>
                  }
                  secondary={describe(rule)}
                />
              </ListItem>
            ))}
          </List>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} sx={{ textTransform: 'none' }}>
          Закрыть
        </Button>
      </DialogActions>

      <MailRuleEditor
        open={editorOpen}
        rule={editRule}
        folders={folders}
        onClose={() => setEditorOpen(false)}
        onSaved={reload}
      />
    </Dialog>
  )
}
