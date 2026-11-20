import { useCallback, useMemo, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  Snackbar,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TableSortLabel,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import {
  ArrowUpward as UpIcon,
  CreateNewFolder as NewFolderIcon,
  NoteAdd as NewFileIcon,
  Delete as DeleteIcon,
  Download as DownloadIcon,
  Refresh as RefreshIcon,
} from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapData } from '@/modules/shared/utils/apiResponse'
import FileEditorDialog from '../components/FileEditorDialog'
import type { StorageEntry, StorageFile, StorageListing } from '../types'

type SortKey = 'name' | 'created_at' | 'modified_at'

function formatSize(size: number | null): string {
  if (size === null) return ''
  if (size < 1024) return `${size} Б`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} КБ`
  return `${(size / (1024 * 1024)).toFixed(1)} МБ`
}

function formatDate(value: string): string {
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function joinPath(base: string, name: string): string {
  return base ? `${base}/${name}` : name
}

function parentPath(path: string): string {
  const parts = path.split('/').filter(Boolean)
  parts.pop()
  return parts.join('/')
}

interface PaneProps {
  path: string
  sortKey: SortKey
  sortDir: 'asc' | 'desc'
  onSort: (key: SortKey) => void
  onNavigate: (path: string) => void
  onOpenFile: (entry: StorageEntry) => void
  onDownload: (entry: StorageEntry) => void
  onSelectionChange: (entry: StorageEntry | null) => void
  selectedPath: string | null
}

function FilePane(props: PaneProps) {
  const { path, sortKey, sortDir, onSort, onNavigate, onOpenFile, onDownload, onSelectionChange, selectedPath } = props

  const { data, isLoading, isError, error } = useQuery<StorageListing>({
    queryKey: ['storage-list', path],
    queryFn: async () =>
      unwrapData<StorageListing>(await apiClient.get('/v1/storage/files', { params: { path } })) as StorageListing,
    staleTime: 5_000,
  })

  const entries = useMemo(() => {
    const list = [...(data?.entries ?? [])]
    const dir = sortDir === 'asc' ? 1 : -1
    list.sort((a, b) => {
      // Folders always stay at the top.
      if (a.type !== b.type) return a.type === 'dir' ? -1 : 1
      if (sortKey === 'name') return a.name.localeCompare(b.name, 'ru') * dir
      const av = sortKey === 'created_at' ? a.created_at : a.modified_at
      const bv = sortKey === 'created_at' ? b.created_at : b.modified_at
      return av.localeCompare(bv) * dir
    })
    return list
  }, [data?.entries, sortKey, sortDir])

  return (
    <Box
      sx={{
        flex: 1,
        minWidth: 0,
        minHeight: 0,
        display: 'flex',
        flexDirection: 'column',
        border: '1px solid',
        borderColor: 'divider',
        borderRadius: 1,
        bgcolor: 'background.paper',
        overflow: 'hidden',
      }}
    >
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, px: 1, py: 0.5, bgcolor: 'action.hover' }}>
        <Tooltip title="Вверх">
          <span>
            <IconButton size="small" disabled={!path} onClick={() => onNavigate(parentPath(path))}>
              <UpIcon fontSize="small" />
            </IconButton>
          </span>
        </Tooltip>
        <Typography noWrap sx={{ fontSize: '0.82rem', flex: 1 }} title={`/${path}`}>
          Хранилище{path ? '\\' + path.replace(/\//g, '\\') : ''}
        </Typography>
      </Box>

      <Box sx={{ flex: 1, overflow: 'auto' }}>
        <Table size="small" stickyHeader>
          <TableHead>
            <TableRow>
              <TableCell sortDirection={sortKey === 'name' ? sortDir : false}>
                <TableSortLabel
                  active={sortKey === 'name'}
                  direction={sortKey === 'name' ? sortDir : 'asc'}
                  onClick={() => onSort('name')}
                >
                  Имя
                </TableSortLabel>
              </TableCell>
              <TableCell sortDirection={sortKey === 'created_at' ? sortDir : false}>
                <TableSortLabel
                  active={sortKey === 'created_at'}
                  direction={sortKey === 'created_at' ? sortDir : 'asc'}
                  onClick={() => onSort('created_at')}
                >
                  Дата создания
                </TableSortLabel>
              </TableCell>
              <TableCell sortDirection={sortKey === 'modified_at' ? sortDir : false}>
                <TableSortLabel
                  active={sortKey === 'modified_at'}
                  direction={sortKey === 'modified_at' ? sortDir : 'asc'}
                  onClick={() => onSort('modified_at')}
                >
                  Дата изменения
                </TableSortLabel>
              </TableCell>
              <TableCell>Тип</TableCell>
              <TableCell align="right">Размер</TableCell>
              <TableCell align="right" />
            </TableRow>
          </TableHead>
          <TableBody>
            {isLoading && (
              <TableRow>
                <TableCell colSpan={6} align="center" sx={{ py: 3 }}>
                  <CircularProgress size={22} />
                </TableCell>
              </TableRow>
            )}
            {isError && (
              <TableRow>
                <TableCell colSpan={6}>
                  <Alert severity="error">
                    {(error as any)?.response?.data?.message ?? 'Не удалось открыть каталог'}
                  </Alert>
                </TableCell>
              </TableRow>
            )}
            {!isLoading && entries.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} align="center" sx={{ color: 'text.secondary', py: 3 }}>
                  Папка пуста
                </TableCell>
              </TableRow>
            )}
            {entries.map((entry) => {
              const isSelected = selectedPath === entry.path
              return (
                <TableRow
                  key={entry.path}
                  hover
                  selected={isSelected}
                  onClick={() => onSelectionChange(entry)}
                  onDoubleClick={() => (entry.type === 'dir' ? onNavigate(entry.path) : onOpenFile(entry))}
                  sx={{ cursor: 'pointer' }}
                >
                  <TableCell>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                      <span>{entry.type === 'dir' ? '📁' : '📄'}</span>
                      <Typography component="span" sx={{ fontSize: '0.85rem' }}>
                        {entry.name}
                      </Typography>
                    </Box>
                  </TableCell>
                  <TableCell sx={{ fontSize: '0.8rem', color: 'text.secondary' }}>
                    {formatDate(entry.created_at)}
                  </TableCell>
                  <TableCell sx={{ fontSize: '0.8rem', color: 'text.secondary' }}>
                    {formatDate(entry.modified_at)}
                  </TableCell>
                  <TableCell sx={{ fontSize: '0.8rem', color: 'text.secondary' }}>{entry.kind}</TableCell>
                  <TableCell align="right" sx={{ fontSize: '0.8rem' }}>
                    {formatSize(entry.size)}
                  </TableCell>
                  <TableCell align="right">
                    {entry.type === 'file' && (
                      <Tooltip title="Скачать">
                        <IconButton
                          size="small"
                          onClick={(e) => {
                            e.stopPropagation()
                            onDownload(entry)
                          }}
                        >
                          <DownloadIcon fontSize="small" />
                        </IconButton>
                      </Tooltip>
                    )}
                  </TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </Table>
      </Box>
    </Box>
  )
}

export default function FileManagerPage() {
  const queryClient = useQueryClient()
  const [path, setPath] = useState('')
  const [selected, setSelected] = useState<StorageEntry | null>(null)
  const [sort, setSort] = useState<{ key: SortKey; dir: 'asc' | 'desc' }>({ key: 'name', dir: 'asc' })
  const [editing, setEditing] = useState<StorageFile | null>(null)
  const [notice, setNotice] = useState<{ type: 'success' | 'error'; text: string } | null>(null)
  const [prompt, setPrompt] = useState<{ kind: 'dir' | 'file' } | null>(null)
  const [promptValue, setPromptValue] = useState('')

  const { data: listing } = useQuery<StorageListing>({
    queryKey: ['storage-list', path],
    queryFn: async () =>
      unwrapData<StorageListing>(await apiClient.get('/v1/storage/files', { params: { path } })) as StorageListing,
    staleTime: 5_000,
  })

  const configured = listing?.configured ?? true
  const notConfiguredMessage = listing?.message || 'Файловое хранилище не настроено. Обратитесь к администратору.'

  const reload = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: ['storage-list'] })
  }, [queryClient])

  const navigate = useCallback((target: string) => {
    setPath(target)
    setSelected(null)
  }, [])

  const openFile = useCallback(async (entry: StorageEntry) => {
    try {
      const file = unwrapData<StorageFile>(await apiClient.get('/v1/storage/file', { params: { path: entry.path } }))
      if (file) setEditing(file)
    } catch (e: any) {
      setNotice({ type: 'error', text: e?.response?.data?.message || 'Не удалось открыть файл' })
    }
  }, [])

  const download = useCallback(async (entry: StorageEntry) => {
    try {
      const res = await apiClient.get('/v1/storage/download', {
        params: { path: entry.path },
        responseType: 'blob',
        timeout: 0,
      })
      const blobUrl = URL.createObjectURL(res.data as Blob)
      const link = document.createElement('a')
      link.href = blobUrl
      link.download = entry.name
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(blobUrl)
    } catch {
      setNotice({ type: 'error', text: 'Не удалось скачать файл' })
    }
  }, [])

  const downloadByPath = useCallback(
    (path: string, name: string) => {
      download({ name, path } as StorageEntry)
    },
    [download],
  )

  const createEntry = useCallback(async () => {
    if (!prompt) return
    const name = promptValue.trim()
    if (!name) return
    try {
      const res = await apiClient.post('/v1/storage/entry', {
        path: joinPath(path, name),
        type: prompt.kind,
        content: prompt.kind === 'file' ? '' : undefined,
      })
      const createdPath = joinPath(path, name)
      setPrompt(null)
      setPromptValue('')
      reload()
      if (prompt.kind === 'file') {
        const file = unwrapData<StorageFile>(await apiClient.get('/v1/storage/file', { params: { path: createdPath } }))
        if (file) setEditing(file)
      }
      void res
    } catch (e: any) {
      setNotice({ type: 'error', text: e?.response?.data?.message || 'Не удалось создать' })
    }
  }, [prompt, promptValue, path, reload])

  const deleteSelection = useCallback(async () => {
    if (!selected) return
    if (!window.confirm(`Удалить «${selected.name}»?`)) return
    try {
      await apiClient.delete('/v1/storage/entry', { data: { path: selected.path } })
      setSelected(null)
      reload()
    } catch (e: any) {
      setNotice({ type: 'error', text: e?.response?.data?.message || 'Не удалось удалить' })
    }
  }, [selected, reload])

  const handleSort = useCallback((key: SortKey) => {
    setSort((prev) => (prev.key === key ? { key, dir: prev.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' }))
  }, [])

  return (
    <Box sx={{ display: 'flex', flexDirection: 'column', height: 'calc(100vh - 120px)', gap: 1 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
        <Typography sx={{ fontSize: '1.1rem', fontWeight: 700, mr: 1 }}>Файлы</Typography>
        <Button
          size="small"
          startIcon={<NewFolderIcon />}
          disabled={!configured}
          onClick={() => {
            setPrompt({ kind: 'dir' })
            setPromptValue('')
          }}
          sx={{ textTransform: 'none' }}
        >
          Новая папка
        </Button>
        <Button
          size="small"
          startIcon={<NewFileIcon />}
          disabled={!configured}
          onClick={() => {
            setPrompt({ kind: 'file' })
            setPromptValue('')
          }}
          sx={{ textTransform: 'none' }}
        >
          Новый файл
        </Button>
        <Button
          size="small"
          startIcon={<DeleteIcon />}
          disabled={!configured || !selected}
          onClick={deleteSelection}
          sx={{ textTransform: 'none' }}
        >
          Удалить
        </Button>
        <Button size="small" startIcon={<RefreshIcon />} onClick={reload} sx={{ textTransform: 'none' }}>
          Обновить
        </Button>
      </Box>

      {configured && listing?.root && (
        <Typography noWrap title={listing.root} sx={{ fontSize: '0.75rem', color: 'text.secondary' }}>
          {listing.protocol ? `${listing.protocol.toUpperCase()} · ` : ''}
          {listing.root}
        </Typography>
      )}

      {!configured && <Alert severity="warning">{notConfiguredMessage}</Alert>}

      <Box sx={{ flex: 1, minHeight: 0, display: 'flex' }}>
        <FilePane
          path={path}
          sortKey={sort.key}
          sortDir={sort.dir}
          onSort={handleSort}
          onNavigate={navigate}
          onOpenFile={openFile}
          onDownload={download}
          onSelectionChange={setSelected}
          selectedPath={selected?.path ?? null}
        />
      </Box>

      <FileEditorDialog
        file={editing}
        onClose={() => setEditing(null)}
        onSaved={(text) => {
          setNotice({ type: 'success', text })
          reload()
        }}
        onError={(text) => {
          setNotice({ type: 'error', text })
        }}
        onDownload={downloadByPath}
      />

      <Dialog open={!!prompt} onClose={() => setPrompt(null)}>
        <DialogTitle>{prompt?.kind === 'dir' ? 'Новая папка' : 'Новый файл'}</DialogTitle>
        <DialogContent>
          <TextField
            autoFocus
            fullWidth
            sx={{ mt: 1, minWidth: 340 }}
            label="Имя (для файла укажите расширение, напр. note.txt, table.csv, page.html)"
            value={promptValue}
            onChange={(e) => setPromptValue(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') createEntry()
            }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setPrompt(null)} sx={{ textTransform: 'none' }}>
            Отмена
          </Button>
          <Button variant="contained" onClick={createEntry} sx={{ textTransform: 'none' }}>
            Создать
          </Button>
        </DialogActions>
      </Dialog>

      <Snackbar
        open={!!notice}
        autoHideDuration={4000}
        onClose={() => setNotice(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}
      >
        <Alert severity={notice?.type ?? 'info'} onClose={() => setNotice(null)}>
          {notice?.text}
        </Alert>
      </Snackbar>
    </Box>
  )
}
