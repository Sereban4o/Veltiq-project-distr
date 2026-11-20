import { Box, Typography } from '@mui/material'
import type { PageDiffResponse } from '@/types'

interface DiffViewProps {
  diff: PageDiffResponse['diff']
}

const COLORS: Record<string, { bg: string; color: string; prefix: string }> = {
  added: { bg: '#e6ffed', color: '#22863a', prefix: '+' },
  removed: { bg: '#ffeef0', color: '#cb2431', prefix: '−' },
  context: { bg: 'transparent', color: '#24292e', prefix: ' ' },
}

export default function DiffView({ diff }: DiffViewProps) {
  if (!diff || diff.lines.length === 0) {
    return (
      <Typography variant="body2" color="text.secondary" sx={{ p: 2 }}>
        Между версиями нет различий
      </Typography>
    )
  }

  return (
    <Box sx={{ border: '1px solid #e1e4e8', borderRadius: 1, overflow: 'hidden' }}>
      <Box
        sx={{
          px: 1.5,
          py: 0.75,
          bgcolor: '#f6f8fa',
          borderBottom: '1px solid #e1e4e8',
          display: 'flex',
          gap: 2,
        }}
      >
        <Typography variant="caption" sx={{ color: '#22863a', fontWeight: 600 }}>
          +{diff.stats.added}
        </Typography>
        <Typography variant="caption" sx={{ color: '#cb2431', fontWeight: 600 }}>
          −{diff.stats.removed}
        </Typography>
      </Box>

      <Box sx={{ maxHeight: 420, overflow: 'auto', fontFamily: 'monospace', fontSize: '0.8rem' }}>
        {diff.lines.map((line, index) => {
          const style = COLORS[line.type] ?? COLORS.context
          return (
            <Box
              key={index}
              sx={{
                display: 'flex',
                bgcolor: style.bg,
                px: 1,
                py: 0.25,
                whiteSpace: 'pre-wrap',
                wordBreak: 'break-word',
              }}
            >
              <Box sx={{ width: 16, flexShrink: 0, color: style.color, userSelect: 'none' }}>{style.prefix}</Box>
              <Box sx={{ color: style.color }}>{line.text || '\u00A0'}</Box>
            </Box>
          )
        })}
      </Box>
    </Box>
  )
}
