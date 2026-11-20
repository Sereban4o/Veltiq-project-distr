import { useState } from 'react'
import {
  Box,
  Card,
  CardContent,
  CircularProgress,
  Grid,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  ToggleButton,
  ToggleButtonGroup,
  Typography,
} from '@mui/material'
import { BarChart } from '@mui/x-charts/BarChart'
import { useQuery } from '@tanstack/react-query'
import { crmApi } from '../api'
import type { CrmReportRevenue } from '../types'

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

const PERIODS = [
  { value: 'month', label: 'Месяц' },
  { value: 'quarter', label: 'Квартал' },
  { value: 'year', label: 'Год' },
] as const

export default function ReportsTab() {
  const [period, setPeriod] = useState<'month' | 'quarter' | 'year'>('month')

  const { data: summary, isLoading: loadingSummary } = useQuery({
    queryKey: ['crm-report-summary'],
    queryFn: crmApi.reportSummary,
  })
  const { data: funnelData } = useQuery({ queryKey: ['crm-report-funnel'], queryFn: crmApi.reportFunnel })
  const { data: conversion } = useQuery({ queryKey: ['crm-report-conversion'], queryFn: crmApi.reportConversion })
  const { data: revenue, isLoading: loadingRevenue } = useQuery<CrmReportRevenue>({
    queryKey: ['crm-report-revenue', period],
    queryFn: () => crmApi.reportRevenue({ period }),
  })

  if (loadingSummary || !summary) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}>
        <CircularProgress />
      </Box>
    )
  }

  const funnel = funnelData ?? []

  return (
    <Stack spacing={3}>
      <Grid container spacing={2}>
        <Grid item xs={6} md={3}>
          <StatCard label="Выиграно сделок" value={String(summary.won_count)} color="success.main" />
        </Grid>
        <Grid item xs={6} md={3}>
          <StatCard label="Проиграно сделок" value={String(summary.lost_count)} color="error.main" />
        </Grid>
        <Grid item xs={6} md={3}>
          <StatCard label="Конверсия в успех" value={`${summary.win_rate}%`} />
        </Grid>
        <Grid item xs={6} md={3}>
          <StatCard label="Средний чек" value={money(summary.avg_deal_size)} />
        </Grid>
        <Grid item xs={6} md={3}>
          <StatCard label="Средний цикл сделки" value={`${summary.avg_cycle_days} дн.`} />
        </Grid>
        <Grid item xs={6} md={3}>
          <StatCard label="Открытых сделок" value={String(summary.open_count)} />
        </Grid>
        <Grid item xs={6} md={3}>
          <StatCard label="Сумма в работе" value={money(summary.open_amount)} color="primary.main" />
        </Grid>
      </Grid>

      <Card variant="outlined" sx={{ borderRadius: 2 }}>
        <CardContent>
          <Typography sx={{ fontWeight: 700, mb: 0.5 }}>Воронка продаж</Typography>
          <Typography sx={{ fontSize: '0.78rem', color: 'text.secondary', mb: 2 }}>
            Сумма открытых сделок и прогноз с учётом вероятности
          </Typography>
          {funnel.length === 0 ? (
            <Typography sx={{ color: 'text.secondary', fontSize: '0.85rem' }}>Нет данных</Typography>
          ) : (
            <BarChart
              height={280}
              xAxis={[{ scaleType: 'band', data: funnel.map((s) => s.name) }]}
              series={[
                { data: funnel.map((s) => s.amount), label: 'Сумма', color: '#3390ec' },
                { data: funnel.map((s) => s.weighted_amount), label: 'Прогноз', color: '#36B37E' },
              ]}
              margin={{ left: 70, right: 10, top: 20, bottom: 40 }}
            />
          )}
        </CardContent>
      </Card>

      <Card variant="outlined" sx={{ borderRadius: 2 }}>
        <CardContent>
          <Typography sx={{ fontWeight: 700, mb: 0.5 }}>Конверсия по этапам</Typography>
          <Typography sx={{ fontSize: '0.78rem', color: 'text.secondary', mb: 2 }}>
            Доля сделок, перешедших на следующий этап
          </Typography>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Переход</TableCell>
                <TableCell align="right">Дошло</TableCell>
                <TableCell align="right">Перешло</TableCell>
                <TableCell align="right">Конверсия</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {(conversion?.pairs ?? []).length === 0 && (
                <TableRow>
                  <TableCell colSpan={4} align="center" sx={{ color: 'text.secondary', py: 3 }}>
                    Нет данных о переходах
                  </TableCell>
                </TableRow>
              )}
              {(conversion?.pairs ?? []).map((pair) => (
                <TableRow key={`${pair.from.id}-${pair.to.id}`} hover>
                  <TableCell sx={{ fontSize: '0.85rem' }}>
                    {pair.from.name} → {pair.to.name}
                  </TableCell>
                  <TableCell align="right">{pair.entered}</TableCell>
                  <TableCell align="right">{pair.converted}</TableCell>
                  <TableCell align="right" sx={{ fontWeight: 600 }}>
                    {pair.rate}%
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Card variant="outlined" sx={{ borderRadius: 2 }}>
        <CardContent>
          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 2 }}>
            <Typography sx={{ fontWeight: 700 }}>Выручка по периодам</Typography>
            <ToggleButtonGroup size="small" exclusive value={period} onChange={(_, value) => value && setPeriod(value)}>
              {PERIODS.map((p) => (
                <ToggleButton key={p.value} value={p.value} sx={{ textTransform: 'none', px: 2 }}>
                  {p.label}
                </ToggleButton>
              ))}
            </ToggleButtonGroup>
          </Box>
          {loadingRevenue || !revenue ? (
            <Box sx={{ display: 'flex', justifyContent: 'center', py: 4 }}>
              <CircularProgress size={28} />
            </Box>
          ) : revenue.buckets.length === 0 ? (
            <Typography sx={{ color: 'text.secondary', fontSize: '0.85rem' }}>
              Нет выигранных сделок за период
            </Typography>
          ) : (
            <BarChart
              height={280}
              xAxis={[{ scaleType: 'band', data: revenue.buckets.map((b) => b.label) }]}
              series={[{ data: revenue.buckets.map((b) => b.won_amount), label: 'Выручка', color: '#36B37E' }]}
              margin={{ left: 70, right: 10, top: 20, bottom: 40 }}
            />
          )}
        </CardContent>
      </Card>
    </Stack>
  )
}
