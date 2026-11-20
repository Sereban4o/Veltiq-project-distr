import { Box, Chip, Divider, List, ListItemButton, ListItemText, Typography } from '@mui/material'
import type { SearchGroup, SearchHit, SearchType } from '../types'
import { SEARCH_TYPES, SEARCH_TYPE_LABELS } from '../types'

interface SearchResultsProps {
  groups?: Partial<Record<SearchType, SearchGroup>>
  onSelect: (hit: SearchHit) => void
}

/** Renders search results grouped by entity type. */
export default function SearchResults({ groups, onSelect }: SearchResultsProps) {
  if (!groups) return null

  return (
    <Box>
      {SEARCH_TYPES.map((type) => {
        const group = groups[type]
        if (!group || group.hits.length === 0) return null

        return (
          <Box key={type} sx={{ mb: 1 }}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, px: 2, py: 0.5 }}>
              <Typography
                variant="caption"
                sx={{ fontWeight: 700, textTransform: 'uppercase', color: 'text.secondary' }}
              >
                {SEARCH_TYPE_LABELS[type]}
              </Typography>
              <Chip size="small" label={group.total} sx={{ height: 18, fontSize: '0.7rem' }} />
            </Box>
            <List dense disablePadding>
              {group.hits.map((hit) => (
                <ListItemButton key={`${type}-${hit.id}`} onClick={() => onSelect(hit)} sx={{ mx: 1, borderRadius: 1 }}>
                  <ListItemText
                    primary={hit.title || '—'}
                    secondary={hit.snippet || undefined}
                    primaryTypographyProps={{ noWrap: true, fontSize: '0.9rem' }}
                    secondaryTypographyProps={{ noWrap: true, fontSize: '0.78rem' }}
                  />
                </ListItemButton>
              ))}
            </List>
            <Divider sx={{ mt: 1 }} />
          </Box>
        )
      })}
    </Box>
  )
}
