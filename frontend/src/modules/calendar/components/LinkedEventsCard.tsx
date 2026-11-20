import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Box, Button, Card, CardContent, Stack, Typography } from '@mui/material'
import AddIcon from '@mui/icons-material/Add'
import EventIcon from '@mui/icons-material/Event'
import { addYears, format, subYears } from 'date-fns'
import { calendarApi } from '../api'
import EventDialog from './EventDialog'

interface Props {
  issueId?: number
  dealId?: number
  title?: string
}

export default function LinkedEventsCard({ issueId, dealId, title = 'События календаря' }: Props) {
  const navigate = useNavigate()
  const [dialogOpen, setDialogOpen] = useState(false)

  const from = format(subYears(new Date(), 1), 'yyyy-MM-dd')
  const to = format(addYears(new Date(), 1), 'yyyy-MM-dd')

  const { data } = useQuery({
    queryKey: ['calendar', 'linked', issueId ?? null, dealId ?? null],
    queryFn: () => calendarApi.events({ from, to, issue_id: issueId, deal_id: dealId }),
    enabled: !!(issueId || dealId),
  })

  const items = data?.items ?? []

  return (
    <Card sx={{ mb: 3 }}>
      <CardContent>
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1 }}>
          <Typography variant="h6">{title}</Typography>
          <Button size="small" startIcon={<AddIcon />} onClick={() => setDialogOpen(true)}>
            Создать
          </Button>
        </Box>

        {items.length === 0 ? (
          <Typography variant="body2" color="text.secondary">
            Нет связанных событий
          </Typography>
        ) : (
          <Stack spacing={1}>
            {items.map((item) => (
              <Box
                key={item.occurrence_key}
                sx={{
                  display: 'flex',
                  gap: 1,
                  alignItems: 'flex-start',
                  p: 1,
                  borderRadius: 1,
                  cursor: 'pointer',
                  '&:hover': { bgcolor: 'action.hover' },
                }}
                onClick={() => navigate(`/calendar?event=${item.meta?.event_id}&date=${item.date}`)}
              >
                <EventIcon sx={{ fontSize: 18, color: item.meta?.color || '#FFAB00', mt: 0.25 }} />
                <Box sx={{ minWidth: 0 }}>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    {item.title}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {item.date}
                    {item.meta?.all_day ? ' · весь день' : ` · ${new Date(item.meta?.starts_at).toLocaleTimeString()}`}
                    {item.meta?.recurring ? ' · повторяющееся' : ''}
                  </Typography>
                </Box>
              </Box>
            ))}
          </Stack>
        )}
      </CardContent>

      <EventDialog
        open={dialogOpen}
        defaultIssueIds={issueId ? [issueId] : undefined}
        defaultDealIds={dealId ? [dealId] : undefined}
        onClose={() => setDialogOpen(false)}
      />
    </Card>
  )
}
