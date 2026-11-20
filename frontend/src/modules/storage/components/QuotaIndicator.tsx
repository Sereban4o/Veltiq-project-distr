import { Box, LinearProgress, Tooltip, Typography } from '@mui/material'
import type { StorageQuota } from '../types'

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} Б`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} КБ`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} МБ`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} ГБ`
}

interface Props {
  quota: StorageQuota
}

export default function QuotaIndicator({ quota }: Props) {
  const percent = quota.percent ?? 0
  const colorless = quota.limit === null

  const color = percent >= 100 ? 'error' : percent >= 90 ? 'warning' : 'primary'

  return (
    <Tooltip
      title={
        <Box sx={{ fontSize: '0.75rem' }}>
          <div>Файлы: {formatBytes(quota.used_live)}</div>
          <div>Корзина: {formatBytes(quota.used_trash)}</div>
          <div>Версии: {formatBytes(quota.used_versions)}</div>
          <div>Лимит: {quota.limit === null ? 'без ограничений' : formatBytes(quota.limit)}</div>
        </Box>
      }
    >
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, minWidth: 220 }}>
        <LinearProgress
          variant={colorless ? 'indeterminate' : 'determinate'}
          value={percent}
          color={color as any}
          sx={{ flex: 1, height: 6, borderRadius: 3 }}
        />
        <Typography sx={{ fontSize: '0.75rem', color: quotientTextColor(percent, colorless), whiteSpace: 'nowrap' }}>
          {quota.limit === null
            ? `${formatBytes(quota.used)} · без лимита`
            : `${formatBytes(quota.used)} из ${formatBytes(quota.limit)}`}
        </Typography>
      </Box>
    </Tooltip>
  )
}

function quotientTextColor(percent: number, colorless: boolean): string {
  if (colorless) return 'text.secondary'
  if (percent >= 100) return 'error.main'
  if (percent >= 90) return 'warning.main'
  return 'text.secondary'
}
