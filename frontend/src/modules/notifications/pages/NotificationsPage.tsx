import { useState } from 'react'
import {
  Box,
  Typography,
  Paper,
  List,
  ListItem,
  ListItemText,
  Button,
  Divider,
  Chip,
  Pagination,
  FormControlLabel,
  Switch,
  Stack,
  CircularProgress,
} from '@mui/material'
import {
  useMarkAllNotificationsAsRead,
  useMarkNotificationAsRead,
  useNotifications,
  useNotificationPreferences,
  notificationSummary,
} from '@/modules/notifications/api/notificationApi'

export default function NotificationsPage() {
  const [unreadOnly, setUnreadOnly] = useState(false)
  const [page, setPage] = useState(1)

  const { data, isLoading } = useNotifications({ per_page: 20, page, unread: unreadOnly || undefined })
  const { data: preferences } = useNotificationPreferences()
  const markAsRead = useMarkNotificationAsRead()
  const markAllAsRead = useMarkAllNotificationsAsRead()

  const notifications = data?.data ?? []
  const labels = Object.assign(
    {} as Record<string, string>,
    ...Object.values(preferences?.types ?? {}).map((group) => group.types),
  )

  const typeLabel = (type: string) => labels[type] ?? type

  return (
    <Box>
      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 3 }}>
        <Typography variant="h4" sx={{ fontWeight: 600 }}>
          Уведомления
        </Typography>
        <Button variant="outlined" onClick={() => markAllAsRead.mutate()} disabled={markAllAsRead.isPending}>
          Отметить всё прочитанным
        </Button>
      </Box>

      <Paper sx={{ p: 2, mb: 2 }}>
        <Stack direction="row" spacing={3} alignItems="center">
          <FormControlLabel
            control={
              <Switch
                checked={unreadOnly}
                onChange={(e) => {
                  setUnreadOnly(e.target.checked)
                  setPage(1)
                }}
              />
            }
            label="Только непрочитанные"
          />
        </Stack>
      </Paper>

      <Paper>
        {isLoading ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', p: 4 }}>
            <CircularProgress />
          </Box>
        ) : (
          <List sx={{ p: 0 }}>
            {notifications.map((notification, index) => (
              <Box key={notification.id}>
                {index > 0 && <Divider />}
                <ListItem
                  sx={{ bgcolor: notification.read ? 'transparent' : 'action.hover', alignItems: 'flex-start' }}
                  secondaryAction={
                    !notification.read ? (
                      <Button size="small" onClick={() => markAsRead.mutate(notification.id)}>
                        Прочитано
                      </Button>
                    ) : null
                  }
                >
                  <ListItemText
                    primary={
                      <Stack direction="row" spacing={1} alignItems="center">
                        <Chip size="small" label={typeLabel(notification.type)} />
                        <Typography component="span">{notificationSummary(notification)}</Typography>
                      </Stack>
                    }
                    secondary={notification.created_at?.slice(0, 16).replace('T', ' ')}
                  />
                </ListItem>
              </Box>
            ))}
            {notifications.length === 0 && (
              <Box sx={{ p: 4, textAlign: 'center', color: 'text.secondary' }}>Нет уведомлений</Box>
            )}
          </List>
        )}
      </Paper>

      {(data?.last_page ?? 1) > 1 && (
        <Box sx={{ display: 'flex', justifyContent: 'center', mt: 3 }}>
          <Pagination count={data?.last_page ?? 1} page={page} onChange={(_, value) => setPage(value)} />
        </Box>
      )}
    </Box>
  )
}
