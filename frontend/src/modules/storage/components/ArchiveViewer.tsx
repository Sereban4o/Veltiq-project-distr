import { Alert, Box, CircularProgress, List, ListItem, ListItemText, Typography } from '@mui/material'
import { Folder as FolderIcon, InsertDriveFile as FileIcon } from '@mui/icons-material'
import { useQuery } from '@tanstack/react-query'
import { fetchArchive, storageKeys } from '../api/storageApi'

interface Props {
  path: string
}

function formatSize(size: number | null): string {
  if (size === null) return ''
  if (size < 1024) return `${size} Б`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} КБ`
  return `${(size / (1024 * 1024)).toFixed(1)} МБ`
}

export default function ArchiveViewer({ path }: Props) {
  const { data, isLoading, isError, error } = useQuery({
    queryKey: storageKeys.archive(path),
    queryFn: () => fetchArchive(path),
  })

  if (isLoading) return <CircularProgress size={22} />
  if (isError) {
    return <Alert severity="warning">{(error as any)?.response?.data?.message || 'Не удалось прочитать архив'}</Alert>
  }

  const entries = data?.entries ?? []

  return (
    <Box sx={{ maxHeight: '60vh', overflow: 'auto', border: '1px solid', borderColor: 'divider', borderRadius: 1 }}>
      {entries.length === 0 && (
        <Typography sx={{ p: 2, color: 'text.secondary', fontSize: '0.85rem' }}>Архив пуст</Typography>
      )}
      <List dense>
        {entries.map((entry) => (
          <ListItem key={entry.name}>
            {entry.is_dir ? (
              <FolderIcon fontSize="small" sx={{ mr: 1, color: 'warning.main' }} />
            ) : (
              <FileIcon fontSize="small" sx={{ mr: 1, color: 'action.active' }} />
            )}
            <ListItemText primary={entry.name} secondary={formatSize(entry.size)} />
          </ListItem>
        ))}
      </List>
      {data?.truncated && (
        <Typography sx={{ px: 2, pb: 1, fontSize: '0.75rem', color: 'text.secondary' }}>
          Показаны не все файлы архива
        </Typography>
      )}
    </Box>
  )
}
