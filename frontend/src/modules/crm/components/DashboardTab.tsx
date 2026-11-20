import { Box, Card, CardContent, Chip, CircularProgress, Grid, LinearProgress, Stack, Typography } from '@mui/material'
import { useQuery } from '@tanstack/react-query'
import { crmApi } from '../api'
import type { CrmDashboard } from '../types'

function money(value: number): string {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', maximumFractionDigits: 0 }).format(
    value || 0,
  )
}

function StatCard({ label, value, color }: { label: string; value: string; color?: string }) {
  return (
    <Card variant="outlined" sx={{ borderRadius: 2 }}>
      <CardContent>
        <Typography sx={{ fontSize: '0.8rem', color: 'text.secondary' }}>{label}</Typography>
        <Typography sx={{ fontSize: '1.5rem', fontWeight: 700, color: color ?? 'text.primary' }}>{value}</Typography>
      </CardContent>
    </Card>
  )
}

export default function DashboardTab() {
  const { data, isLoading } = useQuery<CrmDashboard>({ queryKey: ['crm-dashboard'], queryFn: crmApi.dashboard })

  if (isLoading || !data) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}>
        <CircularProgress />
      </Box>
    )
  }

  const maxStage = Math.max(1, ...data.by_stage.map((s) => s.amount))

  return (
    <Stack spacing={3}>
      <Grid container spacing={2}>
        <Grid item xs={6} md={3}>
          <StatCard label="Компании" value={String(data.companies)} />
        </Grid>
        <Grid item xs={6} md={3}>
          <StatCard label="Контакты" value={String(data.contacts)} />
        </Grid>
        <Grid item xs={6} md={3}>
          <StatCard label="Открытые сделки" value={String(data.open_deals)} />
        </Grid>
        <Grid item xs={6} md={3}>
          <StatCard label="Сумма воронки" value={money(data.pipeline_amount)} color="primary.main" />
        </Grid>
        <Grid item xs={6} md={3}>
          <StatCard label="Выиграно (всего)" value={money(data.won_amount)} color="success.main" />
        </Grid>
        <Grid item xs={6} md={3}>
          <StatCard label="Выиграно за месяц" value={String(data.won_this_month)} />
        </Grid>
        <Grid item xs={6} md={3}>
          <StatCard label="Открытые задачи" value={String(data.open_tasks)} />
        </Grid>
        <Grid item xs={6} md={3}>
          <StatCard
            label="Просрочено"
            value={String(data.overdue_tasks)}
            color={data.overdue_tasks ? 'error.main' : undefined}
          />
        </Grid>
      </Grid>

      <Card variant="outlined" sx={{ borderRadius: 2 }}>
        <CardContent>
          <Typography sx={{ fontWeight: 700, mb: 2 }}>Воронка по этапам</Typography>
          <Stack spacing={1.5}>
            {data.by_stage.map((stage) => (
              <Box key={stage.id}>
                <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 0.5 }}>
                  <Typography sx={{ fontSize: '0.85rem', fontWeight: 600 }}>
                    <Box
                      component="span"
                      sx={{
                        display: 'inline-block',
                        width: 10,
                        height: 10,
                        borderRadius: '50%',
                        bgcolor: stage.color,
                        mr: 1,
                      }}
                    />
                    {stage.name}
                  </Typography>
                  <Typography sx={{ fontSize: '0.85rem', color: 'text.secondary' }}>
                    {stage.deals_count} · {money(stage.amount)}
                  </Typography>
                </Box>
                <LinearProgress
                  variant="determinate"
                  value={(stage.amount / maxStage) * 100}
                  sx={{ height: 8, borderRadius: 4, '& .MuiLinearProgress-bar': { bgcolor: stage.color } }}
                />
              </Box>
            ))}
          </Stack>
        </CardContent>
      </Card>

      <Card variant="outlined" sx={{ borderRadius: 2 }}>
        <CardContent>
          <Typography sx={{ fontWeight: 700, mb: 1.5 }}>Последние активности</Typography>
          <Stack spacing={1}>
            {data.recent_activities.length === 0 && (
              <Typography sx={{ color: 'text.secondary', fontSize: '0.85rem' }}>Пока нет активностей</Typography>
            )}
            {data.recent_activities.map((activity) => (
              <Box key={activity.id} sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                <Chip
                  size="small"
                  label={activity.type}
                  color={activity.is_completed ? 'success' : 'default'}
                  variant={activity.is_completed ? 'filled' : 'outlined'}
                />
                <Typography sx={{ fontSize: '0.88rem', flex: 1 }}>{activity.subject}</Typography>
                <Typography sx={{ fontSize: '0.78rem', color: 'text.secondary' }}>
                  {activity.deal?.title || activity.contact?.first_name || ''}
                </Typography>
              </Box>
            ))}
          </Stack>
        </CardContent>
      </Card>
    </Stack>
  )
}
