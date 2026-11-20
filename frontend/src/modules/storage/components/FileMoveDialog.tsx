import { useEffect, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  List,
  ListItemButton,
  ListItemText,
  TextField,
  Typography,
} from '@mui/material'
import { ArrowUpward as UpIcon, Folder as FolderIcon } from '@mui/icons-material'
import { useQuery } from '@tanstack/react-query'
import { fetchListing, moveEntry } from '../api/storageApi'

interface Props {
  open: boolean
  /** Full path of the entry being moved. */
  sourcePath: string | null
  onClose: () => void
  onMoved: (message: string) => void
  onError: (message: string) => void
}

function basename(path: string): string {
  const parts = path.split('/').filter(Boolean)
  return parts[parts.length - 1] ?? path
}

export default function FileMoveDialog({ open, sourcePath, onClose, onMoved, onError }: Props) {
  const [targetDir, setTargetDir] = useState('')
  const [name, setName] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (open && sourcePath) {
      setName(basename(sourcePath))
      const parts = sourcePath.split('/').filter(Boolean)
      parts.pop()
      setTargetDir(parts.join('/'))
    }
  }, [open, sourcePath])

  const { data } = useQuery({
    queryKey: ['storage', 'list', targetDir],
    queryFn: () => fetchListing(targetDir),
    enabled: open,
    staleTime: 5_000,
  })

  const folders = (data?.entries ?? []).filter((entry) => entry.type === 'dir')

  const submit = async () => {
    if (!sourcePath || !name.trim()) return
    const to = targetDir ? `${targetDir}/${name.trim()}` : name.trim()
    setBusy(true)
    try {
      await moveEntry(sourcePath, to)
      onMoved('Перемещено')
      onClose()
    } catch (e: any) {
      if (e?.response?.status === 409) {
        onError('В папке уже есть объект с таким именем')
      } else {
        onError(e?.response?.data?.message || 'Не удалось переместить')
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle>Перемещение «{sourcePath ? basename(sourcePath) : ''}»</DialogTitle>
      <DialogContent dividers>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 1 }}>
          <IconButton
            size="small"
            disabled={!targetDir}
            onClick={() => setTargetDir(targetDir.split('/').slice(0, -1).join('/'))}
          >
            <UpIcon fontSize="small" />
          </IconButton>
          <Typography sx={{ fontSize: '0.85rem' }} noWrap>
            Хранилище{targetDir ? `\\${targetDir.replace(/\//g, '\\')}` : ''}
          </Typography>
        </Box>

        <Box sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 1, maxHeight: 240, overflow: 'auto' }}>
          <List dense disablePadding>
            {folders.length === 0 && <ListItemText sx={{ px: 2, py: 1 }} primary="Нет вложенных папок" />}
            {folders.map((folder) => (
              <ListItemButton key={folder.path} onClick={() => setTargetDir(folder.path)}>
                <FolderIcon fontSize="small" sx={{ mr: 1, color: 'warning.main' }} />
                <ListItemText primary={folder.name} />
              </ListItemButton>
            ))}
          </List>
        </Box>

        <TextField fullWidth label="Имя" value={name} onChange={(e) => setName(e.target.value)} sx={{ mt: 2 }} />

        <Alert severity="info" sx={{ mt: 2 }}>
          Целевой путь: {targetDir ? `${targetDir}/` : ''}
          {name}
        </Alert>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} sx={{ textTransform: 'none' }}>
          Отмена
        </Button>
        <Button variant="contained" disabled={busy || !name.trim()} onClick={submit} sx={{ textTransform: 'none' }}>
          Переместить
        </Button>
      </DialogActions>
    </Dialog>
  )
}
