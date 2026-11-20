import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import {
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  Tooltip,
  Typography,
} from '@mui/material'
import {
  Assignment as TaskIcon,
  TrendingUp as DealIcon,
  Event as EventIcon,
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
} from '@mui/icons-material'
import { format, parseISO } from 'date-fns'
import { ru } from 'date-fns/locale'
import { calendarApi } from '../api'
import type { CalendarItem } from '../types'
import { itemColor } from '../utils'
import EventFormDialog, { type EventFormInitial } from './EventFormDialog'

interface Props {
  open: boolean
  date: string | null
  items: CalendarItem[]
  onClose: () => void
}

function ItemIcon({ item }: { item: CalendarItem }) {
  if (item.type === 'task') return <TaskIcon sx={{ fontSize: 18, color: itemColor(item) }} />
  if (item.type === 'deal') return <DealIcon sx={{ fontSize: 18, color: itemColor(item) }} />
  return <EventIcon sx={{ fontSize: 18, color: itemColor(item) }} />
}

function secondaryLine(item: CalendarItem): string {
  if (item.type === 'task') {
    return [item.meta?.key, item.meta?.status].filter(Boolean).join(' · ')
  }
  if (item.type === 'deal') {
    const amount = new Intl.NumberFormat('ru-RU', {
      style: 'currency',
      currency: item.meta?.currency || 'RUB',
      maximumFractionDigits: 0,
    }).format(Number(item.meta?.amount) || 0)
    return [item.meta?.company, amount].filter(Boolean).join(' · ')
  }
  return item.meta?.description || ''
}

export default function CalendarDayDialog({ open, date, items, onClose }: Props) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<EventFormInitial | null>(null)

  const title = date ? format(parseISO(date), 'd MMMM yyyy', { locale: ru }) : ''

  const handleItemClick = (item: CalendarItem) => {
    if (item.type === 'task') {
      navigate(`/issues/${item.id}`)
      onClose()
    } else if (item.type === 'deal') {
      navigate('/crm', { state: { section: 'deals', dealId: item.id } })
      onClose()
    }
  }

  const handleDelete = async (item: CalendarItem) => {
    if (!window.confirm(`Удалить событие «${item.title}»?`)) return
    await calendarApi.deleteEvent(item.id)
    queryClient.invalidateQueries({ queryKey: ['calendar'] })
  }

  const startEdit = (item: CalendarItem) => {
    setEditing({
      id: item.id,
      title: item.title,
      description: item.meta?.description ?? null,
      event_date: item.date,
      color: item.meta?.color ?? null,
    })
    setFormOpen(true)
  }

  const closeForm = () => {
    setFormOpen(false)
    setEditing(null)
  }

  return (
    <>
      <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
        <DialogTitle>{title}</DialogTitle>
        <DialogContent dividers sx={{ minHeight: 120 }}>
          {items.length === 0 ? (
            <Typography color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>
              На этот день ничего не запланировано
            </Typography>
          ) : (
            <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
              {items.map((item, idx) => (
                <Box
                  key={`${item.type}-${item.id}-${idx}`}
                  sx={{
                    display: 'flex',
                    alignItems: 'flex-start',
                    gap: 1,
                    p: 1,
                    borderRadius: 1.5,
                    border: 1,
                    borderColor: 'divider',
                    cursor: item.type === 'event' ? 'default' : 'pointer',
                    '&:hover': {
                      bgcolor: item.type === 'event' ? 'transparent' : 'action.hover',
                    },
                  }}
                  onClick={() => item.type !== 'event' && handleItemClick(item)}
                >
                  <Box sx={{ mt: 0.25 }}>
                    <ItemIcon item={item} />
                  </Box>
                  <Box sx={{ flex: 1, minWidth: 0 }}>
                    <Typography sx={{ fontSize: '0.85rem', fontWeight: 600 }}>{item.title}</Typography>
                    {secondaryLine(item) && (
                      <Typography sx={{ fontSize: '0.75rem', color: 'text.secondary' }}>
                        {secondaryLine(item)}
                      </Typography>
                    )}
                  </Box>
                  {item.type === 'event' && (
                    <Box sx={{ display: 'flex', gap: 0.25 }}>
                      <Tooltip title="Редактировать">
                        <IconButton
                          size="small"
                          onClick={(e) => {
                            e.stopPropagation()
                            startEdit(item)
                          }}
                        >
                          <EditIcon sx={{ fontSize: 16 }} />
                        </IconButton>
                      </Tooltip>
                      <Tooltip title="Удалить">
                        <IconButton
                          size="small"
                          onClick={(e) => {
                            e.stopPropagation()
                            handleDelete(item)
                          }}
                        >
                          <DeleteIcon sx={{ fontSize: 16 }} />
                        </IconButton>
                      </Tooltip>
                    </Box>
                  )}
                </Box>
              ))}
            </Box>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>Закрыть</Button>
          <Button variant="contained" startIcon={<AddIcon />} onClick={() => setFormOpen(true)}>
            Добавить событие
          </Button>
        </DialogActions>
      </Dialog>

      {date && <EventFormDialog open={formOpen} date={date} initial={editing} onClose={closeForm} />}
    </>
  )
}
