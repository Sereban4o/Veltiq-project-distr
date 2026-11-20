import { useState, type Ref } from 'react'
import {
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  MenuItem,
  Stack,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import { Add as AddIcon, Delete as DeleteIcon, Edit as EditIcon, DragIndicator as DragIcon } from '@mui/icons-material'
import { useDrag, useDrop } from 'react-dnd'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { crmApi } from '../api'
import type { CrmDeal, PipelineStage } from '../types'

const DRAG_TYPE = 'CRM_DEAL'

const empty: Partial<CrmDeal> = {
  title: '',
  company_id: null,
  contact_id: null,
  stage_id: null,
  amount: 0,
  currency: 'RUB',
  probability: 0,
  expected_close_date: null,
  description: '',
}

function money(value: string | number, currency: string): string {
  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: currency || 'RUB',
    maximumFractionDigits: 0,
  }).format(Number(value) || 0)
}

function DealCard({ deal, onEdit, onDelete }: { deal: CrmDeal; onEdit: () => void; onDelete: () => void }) {
  const [{ isDragging }, drag] = useDrag(
    () => ({
      type: DRAG_TYPE,
      item: { id: deal.id, stageId: deal.stage_id },
      collect: (monitor) => ({ isDragging: monitor.isDragging() }),
    }),
    [deal.id, deal.stage_id],
  )

  return (
    <Box
      ref={drag as unknown as Ref<HTMLDivElement>}
      sx={{
        bgcolor: 'background.paper',
        borderRadius: 2,
        border: 1,
        borderColor: 'divider',
        boxShadow: isDragging ? 1 : 2,
        opacity: isDragging ? 0.4 : 1,
        p: 1.25,
        cursor: 'grab',
        '&:hover': { boxShadow: 3 },
      }}
    >
      <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 0.5 }}>
        <DragIcon sx={{ fontSize: 16, color: 'text.disabled', mt: 0.25 }} />
        <Typography sx={{ fontSize: '0.85rem', fontWeight: 600, flex: 1 }}>{deal.title}</Typography>
        <Tooltip title="Редактировать">
          <IconButton size="small" onClick={onEdit}>
            <EditIcon sx={{ fontSize: 16 }} />
          </IconButton>
        </Tooltip>
        <Tooltip title="Удалить">
          <IconButton size="small" onClick={onDelete}>
            <DeleteIcon sx={{ fontSize: 16 }} />
          </IconButton>
        </Tooltip>
      </Box>
      <Typography sx={{ fontSize: '0.78rem', color: 'text.secondary', pl: 2.5 }}>
        {deal.company?.name || deal.contact?.first_name || '—'}
      </Typography>
      <Typography sx={{ fontSize: '0.9rem', fontWeight: 700, mt: 0.5, pl: 2.5 }}>
        {money(deal.amount, deal.currency)}
      </Typography>
      {deal.expected_close_date && (
        <Typography sx={{ fontSize: '0.72rem', color: 'text.secondary', pl: 2.5 }}>
          до {new Date(deal.expected_close_date).toLocaleDateString('ru-RU')}
        </Typography>
      )}
    </Box>
  )
}

function StageColumn({
  stage,
  deals,
  onDropDeal,
  onEdit,
  onDelete,
}: {
  stage: PipelineStage
  deals: CrmDeal[]
  onDropDeal: (dealId: number, stageId: number) => void
  onEdit: (deal: CrmDeal) => void
  onDelete: (deal: CrmDeal) => void
}) {
  const [{ isOver }, drop] = useDrop(
    () => ({
      accept: DRAG_TYPE,
      drop: (item: { id: number; stageId: number | null }) => {
        if (item.stageId !== stage.id) onDropDeal(item.id, stage.id)
      },
      collect: (monitor) => ({ isOver: monitor.isOver() }),
    }),
    [stage.id, onDropDeal],
  )

  const sum = deals.reduce((acc, d) => acc + (Number(d.amount) || 0), 0)

  return (
    <Box
      ref={drop as unknown as Ref<HTMLDivElement>}
      sx={{
        width: 270,
        flexShrink: 0,
        borderRadius: 2,
        p: 1,
        border: 2,
        borderColor: isOver ? stage.color : 'transparent',
        bgcolor: isOver ? `${stage.color}18` : 'action.hover',
        transition: 'all 0.15s',
      }}
    >
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 0.5 }}>
        <Box sx={{ width: 10, height: 10, borderRadius: '50%', bgcolor: stage.color }} />
        <Typography sx={{ fontWeight: 700, fontSize: '0.9rem', flex: 1 }}>{stage.name}</Typography>
        <Typography sx={{ fontSize: '0.75rem', color: 'text.secondary' }}>{deals.length}</Typography>
      </Box>
      <Typography sx={{ fontSize: '0.75rem', color: 'text.secondary', mb: 1 }}>{money(sum, 'RUB')}</Typography>

      <Stack spacing={1} sx={{ minHeight: 80 }}>
        {deals.map((deal) => (
          <DealCard key={deal.id} deal={deal} onEdit={() => onEdit(deal)} onDelete={() => onDelete(deal)} />
        ))}
        {deals.length === 0 && (
          <Box
            sx={{
              border: '1px dashed',
              borderColor: 'divider',
              borderRadius: 2,
              py: 3,
              textAlign: 'center',
            }}
          >
            <Typography sx={{ fontSize: '0.75rem', color: 'text.secondary' }}>Перетащите сделку</Typography>
          </Box>
        )}
      </Stack>
    </Box>
  )
}

export default function DealsTab() {
  const queryClient = useQueryClient()
  const [dialog, setDialog] = useState<Partial<CrmDeal> | null>(null)
  const [saving, setSaving] = useState(false)

  const { data: stages = [] } = useQuery<PipelineStage[]>({ queryKey: ['crm-stages'], queryFn: crmApi.stages })
  const { data: deals = [], isLoading } = useQuery<CrmDeal[]>({
    queryKey: ['crm-deals'],
    queryFn: () => crmApi.deals(),
  })
  const { data: companies = [] } = useQuery({ queryKey: ['crm-companies', ''], queryFn: () => crmApi.companies('') })
  const { data: contacts = [] } = useQuery({ queryKey: ['crm-contacts', ''], queryFn: () => crmApi.contacts('') })

  const reload = () => {
    queryClient.invalidateQueries({ queryKey: ['crm-deals'] })
    queryClient.invalidateQueries({ queryKey: ['crm-stages'] })
    queryClient.invalidateQueries({ queryKey: ['crm-dashboard'] })
  }

  const save = async () => {
    if (!dialog?.title?.trim()) return
    setSaving(true)
    try {
      const payload = {
        ...dialog,
        amount: Number(dialog.amount) || 0,
        stage_id: dialog.stage_id ?? stages[0]?.id ?? null,
      }
      if (dialog.id) await crmApi.updateDeal(dialog.id, payload)
      else await crmApi.createDeal(payload)
      setDialog(null)
      reload()
    } finally {
      setSaving(false)
    }
  }

  // Optimistic drag-and-drop between stages (like the kanban board).
  const dropDeal = async (dealId: number, stageId: number) => {
    const previous = queryClient.getQueryData<CrmDeal[]>(['crm-deals'])
    const stage = stages.find((s) => s.id === stageId) ?? null
    queryClient.setQueryData<CrmDeal[]>(['crm-deals'], (old) =>
      old?.map((d) => (d.id === dealId ? { ...d, stage_id: stageId, stage } : d)),
    )
    try {
      await crmApi.moveDeal(dealId, stageId)
      reload()
    } catch {
      queryClient.setQueryData(['crm-deals'], previous)
    }
  }

  const remove = async (deal: CrmDeal) => {
    if (!window.confirm(`Удалить сделку «${deal.title}»?`)) return
    await crmApi.deleteDeal(deal.id)
    reload()
  }

  const set = (field: keyof CrmDeal, value: unknown) => setDialog((prev) => ({ ...prev, [field]: value }))

  if (isLoading) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}>
        <CircularProgress />
      </Box>
    )
  }

  return (
    <Stack spacing={2}>
      <Box sx={{ display: 'flex', alignItems: 'center' }}>
        <Typography sx={{ fontWeight: 700 }}>Воронка продаж</Typography>
        <Box sx={{ flex: 1 }} />
        <Button
          variant="contained"
          startIcon={<AddIcon />}
          onClick={() => setDialog({ ...empty, stage_id: stages[0]?.id ?? null })}
          sx={{ textTransform: 'none' }}
        >
          Сделка
        </Button>
      </Box>

      <Box sx={{ display: 'flex', gap: 1.5, overflowX: 'auto', pb: 1 }}>
        {stages.map((stage) => (
          <StageColumn
            key={stage.id}
            stage={stage}
            deals={deals.filter((d) => d.stage_id === stage.id)}
            onDropDeal={dropDeal}
            onEdit={(deal) => setDialog({ ...deal, amount: Number(deal.amount) })}
            onDelete={remove}
          />
        ))}
      </Box>

      <Dialog open={!!dialog} onClose={() => setDialog(null)} fullWidth maxWidth="sm">
        <DialogTitle>{dialog?.id ? 'Сделка' : 'Новая сделка'}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField
              label="Название"
              value={dialog?.title ?? ''}
              onChange={(e) => set('title', e.target.value)}
              required
            />
            <TextField
              label="Компания"
              select
              value={dialog?.company_id ?? ''}
              onChange={(e) => set('company_id', e.target.value ? Number(e.target.value) : null)}
            >
              <MenuItem value="">—</MenuItem>
              {companies.map((c) => (
                <MenuItem key={c.id} value={c.id}>
                  {c.name}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              label="Контакт"
              select
              value={dialog?.contact_id ?? ''}
              onChange={(e) => set('contact_id', e.target.value ? Number(e.target.value) : null)}
            >
              <MenuItem value="">—</MenuItem>
              {contacts.map((c) => (
                <MenuItem key={c.id} value={c.id}>
                  {c.first_name} {c.last_name}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              label="Этап"
              select
              value={dialog?.stage_id ?? ''}
              onChange={(e) => set('stage_id', Number(e.target.value))}
            >
              {stages.map((s) => (
                <MenuItem key={s.id} value={s.id}>
                  {s.name}
                </MenuItem>
              ))}
            </TextField>
            <Stack direction="row" spacing={2}>
              <TextField
                label="Сумма"
                type="number"
                value={dialog?.amount ?? 0}
                onChange={(e) => set('amount', e.target.value)}
                sx={{ flex: 1 }}
              />
              <TextField
                label="Валюта"
                value={dialog?.currency ?? 'RUB'}
                onChange={(e) => set('currency', e.target.value)}
                sx={{ width: 110 }}
              />
            </Stack>
            <TextField
              label="Вероятность, %"
              type="number"
              value={dialog?.probability ?? 0}
              onChange={(e) => set('probability', Number(e.target.value))}
            />
            <TextField
              label="Ожидаемое закрытие"
              type="date"
              value={dialog?.expected_close_date ? String(dialog.expected_close_date).slice(0, 10) : ''}
              onChange={(e) => set('expected_close_date', e.target.value || null)}
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
