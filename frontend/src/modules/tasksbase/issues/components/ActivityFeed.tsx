import { Box, Card, CardContent, Chip, Divider, Skeleton, Stack, Typography } from '@mui/material'
import { useQuery } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import type { IssueActivity } from '@/types'

const ACTION_LABELS: Record<string, string> = {
  created: 'создал(а) задачу',
  updated: 'изменил(а) поля',
  status_changed: 'изменил(а) статус',
  comment_added: 'добавил(а) комментарий',
  comment_updated: 'изменил(а) комментарий',
  comment_deleted: 'удалил(а) комментарий',
  attachment_added: 'прикрепил(а) файл',
  deleted: 'удалил(а) задачу',
}

const FIELD_LABELS: Record<string, string> = {
  summary: 'Заголовок',
  description: 'Описание',
  status_id: 'Статус',
  priority: 'Приоритет',
  assigned_to: 'Исполнитель',
  issue_type_id: 'Тип',
  start_date: 'Начало',
  due_date: 'Срок',
  story_points: 'Story points',
  epic_id: 'Эпик',
  parent_id: 'Родитель',
  body: 'Текст',
}

const formatValue = (value: unknown): string => {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'да' : 'нет'
  return String(value)
}

export default function ActivityFeed({ issueId }: { issueId: string }) {
  const { data, isLoading } = useQuery<{ data: IssueActivity[] }>({
    queryKey: ['issue-activity', issueId],
    queryFn: async () => apiClient.get(`/v1/tasksbase/issues/${issueId}/activity`).then((r) => r.data),
    enabled: !!issueId,
  })

  const items = data?.data ?? []

  return (
    <Card>
      <CardContent>
        <Typography variant="h6" gutterBottom>
          История изменений
        </Typography>

        {isLoading && <Skeleton variant="rectangular" height={100} />}

        {!isLoading && items.length === 0 && (
          <Typography variant="body2" color="text.secondary">
            Пока нет изменений
          </Typography>
        )}

        <Stack divider={<Divider flexItem />} spacing={1.5}>
          {items.map((item) => (
            <Box key={item.id}>
              <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                <Typography variant="body2" sx={{ fontWeight: 500 }}>
                  {item.user?.full_name || 'Система'}
                </Typography>
                <Typography variant="body2" color="text.secondary">
                  {ACTION_LABELS[item.action] || item.action}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {new Date(item.created_at).toLocaleString()}
                </Typography>
              </Stack>

              {item.changes.length > 0 && (
                <Stack spacing={0.5} sx={{ mt: 0.5 }}>
                  {item.changes.map((change) => (
                    <Stack
                      key={change.field}
                      direction="row"
                      spacing={1}
                      alignItems="center"
                      flexWrap="wrap"
                      useFlexGap
                    >
                      <Chip size="small" label={FIELD_LABELS[change.field] || change.field} />
                      <Typography variant="caption" color="text.secondary" sx={{ textDecoration: 'line-through' }}>
                        {formatValue(change.old)}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        →
                      </Typography>
                      <Typography variant="caption">{formatValue(change.new)}</Typography>
                    </Stack>
                  ))}
                </Stack>
              )}
            </Box>
          ))}
        </Stack>
      </CardContent>
    </Card>
  )
}
