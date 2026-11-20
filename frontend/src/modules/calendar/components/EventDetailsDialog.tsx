import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  Box,
  Button,
  Chip,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  IconButton,
  Stack,
  Tooltip,
  Typography,
} from '@mui/material'
import {
  Close as CloseIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Download as DownloadIcon,
} from '@mui/icons-material'
import { calendarApi } from '../api'
import type { CalendarEvent, CalendarItem, RsvpStatus } from '../types'
import { describeRrule } from '../utils'
import EventDialog from './EventDialog'

interface Props {
  open: boolean
  item?: CalendarItem | null
  onClose: () => void
}

const STATUS_LABEL: Record<RsvpStatus, string> = {
  accepted: 'Принято',
  declined: 'Отклонено',
  tentative: 'Возможно',
  needs_action: 'Нет ответа',
}

const STATUS_COLOR: Record<RsvpStatus, 'success' | 'error' | 'warning' | 'default'> = {
  accepted: 'success',
  declined: 'error',
  tentative: 'warning',
  needs_action: 'default',
}

export default function EventDetailsDialog({ open, item, onClose }: Props) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [editOpen, setEditOpen] = useState(false)

  const event = (item?.meta?.event ?? null) as CalendarEvent | null

  const rsvp = useMutation({
    mutationFn: (status: RsvpStatus) => calendarApi.rsvp(event!.id, status),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['calendar'] }),
  })

  const remove = useMutation({
    mutationFn: (occurrenceStart?: string) => calendarApi.deleteEvent(event!.id, occurrenceStart),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['calendar'] })
      onClose()
    },
  })

  if (!event) {
    return (
      <Dialog open={open} onClose={onClose}>
        <DialogContent>
          <Typography>Событие недоступно</Typography>
        </DialogContent>
      </Dialog>
    )
  }

  const isRecurring = !!event.rrule
  const occurrenceStart = item?.meta?.occurrence_start as string | undefined
  const myStatus = event.my_status
  const canRsvp = !!myStatus && myStatus !== undefined && event.is_organizer !== true
  const canEdit = !!item?.editable

  const handleDelete = () => {
    if (isRecurring) {
      if (window.confirm('Удалить только это повторение? ОК — только это, Отмена — вся серия.')) {
        remove.mutate(occurrenceStart)
      } else if (window.confirm('Удалить всю серию событий?')) {
        remove.mutate(undefined)
      }
      return
    }
    if (window.confirm('Удалить событие?')) remove.mutate(undefined)
  }

  return (
    <>
      <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
        <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <Box sx={{ flexGrow: 1 }}>{event.title}</Box>
          {canEdit && (
            <>
              <Tooltip title="Редактировать">
                <IconButton size="small" onClick={() => setEditOpen(true)}>
                  <EditIcon fontSize="small" />
                </IconButton>
              </Tooltip>
              <Tooltip title="Удалить">
                <IconButton size="small" color="error" onClick={handleDelete}>
                  <DeleteIcon fontSize="small" />
                </IconButton>
              </Tooltip>
            </>
          )}
          <IconButton size="small" onClick={onClose}>
            <CloseIcon fontSize="small" />
          </IconButton>
        </DialogTitle>
        <DialogContent dividers>
          <Stack spacing={1.5}>
            <Typography variant="body2" color="text.secondary">
              {new Date(event.starts_at).toLocaleString()}
              {event.ends_at ? ` — ${new Date(event.ends_at).toLocaleTimeString()}` : ''}
              {event.all_day ? ' (весь день)' : ''}
            </Typography>

            {event.rrule && (
              <Typography variant="body2" color="text.secondary">
                Повторение: {describeRrule(event.rrule)}
              </Typography>
            )}

            {event.location && <Typography variant="body2">Место: {event.location}</Typography>}

            {event.description && <Typography variant="body2">{event.description}</Typography>}

            {event.feed_id && <Chip size="small" label="Внешний календарь" color="secondary" variant="outlined" />}

            {canRsvp && (
              <>
                <Divider />
                <Typography variant="subtitle2">Ваш ответ: {STATUS_LABEL[myStatus as RsvpStatus]}</Typography>
                <Stack direction="row" spacing={1}>
                  <Button
                    size="small"
                    variant={myStatus === 'accepted' ? 'contained' : 'outlined'}
                    color="success"
                    onClick={() => rsvp.mutate('accepted')}
                    disabled={rsvp.isPending}
                  >
                    Принять
                  </Button>
                  <Button
                    size="small"
                    variant={myStatus === 'tentative' ? 'contained' : 'outlined'}
                    color="warning"
                    onClick={() => rsvp.mutate('tentative')}
                    disabled={rsvp.isPending}
                  >
                    Возможно
                  </Button>
                  <Button
                    size="small"
                    variant={myStatus === 'declined' ? 'contained' : 'outlined'}
                    color="error"
                    onClick={() => rsvp.mutate('declined')}
                    disabled={rsvp.isPending}
                  >
                    Отклонить
                  </Button>
                </Stack>
              </>
            )}

            {!!event.participants?.length && (
              <>
                <Divider />
                <Typography variant="subtitle2">Участники</Typography>
                <Stack spacing={0.5}>
                  {event.participants.map((participant) => (
                    <Box key={participant.id} sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                      <Typography variant="body2" sx={{ flexGrow: 1 }}>
                        {participant.user?.full_name || participant.name || participant.email}
                      </Typography>
                      <Chip
                        size="small"
                        label={STATUS_LABEL[participant.status]}
                        color={STATUS_COLOR[participant.status]}
                      />
                    </Box>
                  ))}
                </Stack>
              </>
            )}

            {!!event.links?.length && (
              <>
                <Divider />
                <Typography variant="subtitle2">Связанные записи</Typography>
                <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                  {event.links.map((link) =>
                    link.issue ? (
                      <Chip
                        key={link.id}
                        label={`${link.issue.key}: ${link.issue.summary}`}
                        size="small"
                        clickable
                        onClick={() => {
                          navigate(`/issues/${link.issue!.id}`)
                          onClose()
                        }}
                      />
                    ) : link.deal ? (
                      <Chip
                        key={link.id}
                        label={link.deal.title}
                        size="small"
                        clickable
                        onClick={() => {
                          navigate('/crm', { state: { section: 'deals', dealId: link.deal!.id } })
                          onClose()
                        }}
                      />
                    ) : null,
                  )}
                </Stack>
              </>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button startIcon={<DownloadIcon />} onClick={() => calendarApi.downloadEventIcs(event.id)}>
            .ics
          </Button>
          <Box sx={{ flexGrow: 1 }} />
          <Button onClick={onClose}>Закрыть</Button>
        </DialogActions>
      </Dialog>

      {canEdit && (
        <EventDialog
          open={editOpen}
          initial={event}
          onClose={() => {
            setEditOpen(false)
            onClose()
          }}
        />
      )}
    </>
  )
}
