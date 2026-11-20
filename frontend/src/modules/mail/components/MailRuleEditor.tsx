import { useEffect, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  IconButton,
  MenuItem,
  Stack,
  Switch,
  TextField,
  ToggleButton,
  ToggleButtonGroup,
  Tooltip,
  Typography,
} from '@mui/material'
import { Add as AddIcon, Close as RemoveIcon } from '@mui/icons-material'
import apiClient from '@/modules/shared/api/apiClient'
import type {
  MailFilterRule,
  MailFolderRecord,
  MailRuleCondition,
  MailRuleField,
  MailRuleGroup,
  MailRuleOp,
} from '../types'

interface MailRuleEditorProps {
  open: boolean
  rule?: MailFilterRule | null
  folders: MailFolderRecord[]
  onClose: () => void
  onSaved: () => void
}

const FIELD_LABELS: Record<MailRuleField, string> = {
  from: 'От',
  to: 'Кому',
  subject: 'Тема',
  body: 'Текст',
  has_attachment: 'Есть вложение',
}

const OP_LABELS: Record<MailRuleOp, string> = {
  contains: 'содержит',
  equals: 'равно',
  regex: 'regex',
  is_true: 'да',
}

function newCondition(): MailRuleCondition {
  return { field: 'from', op: 'contains', value: '' }
}

function newGroup(): MailRuleGroup {
  return { match: 'all', conditions: [newCondition()] }
}

export default function MailRuleEditor({ open, rule, folders, onClose, onSaved }: MailRuleEditorProps) {
  const [name, setName] = useState('')
  const [folderId, setFolderId] = useState('')
  const [isActive, setIsActive] = useState(true)
  const [rootMatch, setRootMatch] = useState<'all' | 'any'>('all')
  const [groups, setGroups] = useState<MailRuleGroup[]>([newGroup()])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!open) return
    setName(rule?.name || '')
    setFolderId(
      rule?.action_target_folder_id ? String(rule.action_target_folder_id) : folders[0] ? String(folders[0].id) : '',
    )
    setIsActive(rule?.is_active ?? true)
    setRootMatch(rule?.conditions?.match === 'any' ? 'any' : 'all')
    setGroups(rule?.conditions?.groups?.length ? rule.conditions.groups : [newGroup()])
    setError('')
    // folders is intentionally not a dependency to avoid resetting the form.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, rule])

  const setGroup = (index: number, patch: Partial<MailRuleGroup>) =>
    setGroups((prev) => prev.map((g, i) => (i === index ? { ...g, ...patch } : g)))

  const setCondition = (gi: number, ci: number, patch: Partial<MailRuleCondition>) =>
    setGroups((prev) =>
      prev.map((g, i) =>
        i === gi ? { ...g, conditions: g.conditions.map((c, j) => (j === ci ? { ...c, ...patch } : c)) } : g,
      ),
    )

  const changeField = (gi: number, ci: number, field: MailRuleField) => {
    if (field === 'has_attachment') {
      setCondition(gi, ci, { field, op: 'is_true', value: '' })
    } else {
      setCondition(gi, ci, { field, op: 'contains', value: '' })
    }
  }

  const addCondition = (gi: number) =>
    setGroups((prev) => prev.map((g, i) => (i === gi ? { ...g, conditions: [...g.conditions, newCondition()] } : g)))

  const removeCondition = (gi: number, ci: number) =>
    setGroups((prev) =>
      prev.map((g, i) => (i === gi ? { ...g, conditions: g.conditions.filter((_, j) => j !== ci) } : g)),
    )

  const addGroup = () => setGroups((prev) => [...prev, newGroup()])
  const removeGroup = (gi: number) => setGroups((prev) => prev.filter((_, i) => i !== gi))

  const handleSave = async () => {
    const trimmedName = name.trim()
    if (!trimmedName) {
      setError('Укажите название правила')
      return
    }
    if (!folderId) {
      setError('Создайте папку и выберите её как получателя')
      return
    }

    const normalizedGroups = groups
      .map((g) => ({
        match: g.match === 'any' ? 'any' : 'all',
        conditions: g.conditions
          .filter((c) => c.field === 'has_attachment' || (c.value || '').trim() !== '')
          .map((c) => ({
            field: c.field,
            op: c.field === 'has_attachment' ? 'is_true' : c.op,
            value: c.field === 'has_attachment' ? '' : (c.value || '').trim(),
          })),
      }))
      .filter((g) => g.conditions.length > 0)

    if (!normalizedGroups.length) {
      setError('Добавьте хотя бы одно условие')
      return
    }

    setBusy(true)
    setError('')
    try {
      const payload = {
        name: trimmedName,
        is_active: isActive,
        action_target_folder_id: Number(folderId),
        conditions: { match: rootMatch, groups: normalizedGroups },
      }
      if (rule?.id) {
        await apiClient.patch(`/v1/mail/rules/${rule.id}`, payload)
      } else {
        await apiClient.post('/v1/mail/rules', payload)
      }
      onSaved()
      onClose()
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Не удалось сохранить правило')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle>{rule?.id ? 'Правило' : 'Новое правило'}</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          {error && <Alert severity="error">{error}</Alert>}

          <TextField label="Название правила" value={name} onChange={(e) => setName(e.target.value)} />

          <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', alignItems: 'center' }}>
            <TextField
              select
              label="Переместить в папку"
              value={folderId}
              onChange={(e) => setFolderId(e.target.value)}
              sx={{ minWidth: 240 }}
            >
              {folders.map((f) => (
                <MenuItem key={f.id} value={String(f.id)}>
                  {f.name}
                </MenuItem>
              ))}
            </TextField>
            <FormControlLabel
              control={<Switch checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />}
              label="Активно"
            />
          </Box>

          <Box>
            <Typography variant="subtitle2" sx={{ mb: 1 }}>
              Совпадение между группами
            </Typography>
            <ToggleButtonGroup
              exclusive
              size="small"
              value={rootMatch}
              onChange={(_, value) => value && setRootMatch(value)}
            >
              <ToggleButton value="all">Все (И)</ToggleButton>
              <ToggleButton value="any">Любая (ИЛИ)</ToggleButton>
            </ToggleButtonGroup>
          </Box>

          {groups.map((group, gi) => (
            <Box key={gi} sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 1, p: 1.5 }}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 1 }}>
                <Typography variant="subtitle2" sx={{ flex: 1 }}>
                  Группа {gi + 1}
                </Typography>
                <ToggleButtonGroup
                  exclusive
                  size="small"
                  value={group.match === 'any' ? 'any' : 'all'}
                  onChange={(_, value) => value && setGroup(gi, { match: value })}
                >
                  <ToggleButton value="all">И</ToggleButton>
                  <ToggleButton value="any">ИЛИ</ToggleButton>
                </ToggleButtonGroup>
                <Tooltip title="Удалить группу">
                  <span>
                    <IconButton size="small" onClick={() => removeGroup(gi)} disabled={groups.length === 1}>
                      <RemoveIcon fontSize="small" />
                    </IconButton>
                  </span>
                </Tooltip>
              </Box>

              <Stack spacing={1}>
                {group.conditions.map((condition, ci) => {
                  const isAttachment = condition.field === 'has_attachment'
                  return (
                    <Box key={ci} sx={{ display: 'flex', gap: 1, alignItems: 'center', flexWrap: 'wrap' }}>
                      <TextField
                        select
                        size="small"
                        label="Поле"
                        value={condition.field}
                        onChange={(e) => changeField(gi, ci, e.target.value as MailRuleField)}
                        sx={{ minWidth: 150 }}
                      >
                        {(Object.keys(FIELD_LABELS) as MailRuleField[]).map((f) => (
                          <MenuItem key={f} value={f}>
                            {FIELD_LABELS[f]}
                          </MenuItem>
                        ))}
                      </TextField>

                      <TextField
                        select
                        size="small"
                        label="Условие"
                        value={condition.op}
                        onChange={(e) => setCondition(gi, ci, { op: e.target.value as MailRuleOp })}
                        sx={{ minWidth: 140 }}
                        disabled={isAttachment}
                      >
                        {isAttachment ? (
                          <MenuItem value="is_true">{OP_LABELS.is_true}</MenuItem>
                        ) : (
                          (['contains', 'equals', 'regex'] as MailRuleOp[]).map((op) => (
                            <MenuItem key={op} value={op}>
                              {OP_LABELS[op]}
                            </MenuItem>
                          ))
                        )}
                      </TextField>

                      {!isAttachment && (
                        <TextField
                          size="small"
                          label="Значение"
                          value={condition.value || ''}
                          onChange={(e) => setCondition(gi, ci, { value: e.target.value })}
                          sx={{ flex: 1, minWidth: 180 }}
                        />
                      )}

                      <Tooltip title="Удалить условие">
                        <span>
                          <IconButton
                            size="small"
                            onClick={() => removeCondition(gi, ci)}
                            disabled={group.conditions.length === 1}
                          >
                            <RemoveIcon fontSize="small" />
                          </IconButton>
                        </span>
                      </Tooltip>
                    </Box>
                  )
                })}
                <Box>
                  <Button
                    size="small"
                    startIcon={<AddIcon />}
                    onClick={() => addCondition(gi)}
                    sx={{ textTransform: 'none' }}
                  >
                    Условие
                  </Button>
                </Box>
              </Stack>
            </Box>
          ))}

          <Box>
            <Button size="small" startIcon={<AddIcon />} onClick={addGroup} sx={{ textTransform: 'none' }}>
              Группа
            </Button>
          </Box>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} sx={{ textTransform: 'none' }}>
          Отмена
        </Button>
        <Button variant="contained" onClick={handleSave} disabled={busy} sx={{ textTransform: 'none' }}>
          {busy ? 'Сохранение...' : 'Сохранить'}
        </Button>
      </DialogActions>
    </Dialog>
  )
}
