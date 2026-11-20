import { useState } from 'react'
import {
  Alert,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Tooltip,
  Typography,
} from '@mui/material'
import { Delete as DeleteIcon, Download as DownloadIcon, Restore as RestoreIcon } from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { deleteVersion, downloadVersion, fetchVersions, restoreVersion, storageKeys } from '../api/storageApi'
import type { StorageVersion } from '../types'

interface Props {
  open: boolean
  path: string | null
  name: string
  onClose: () => void
  onChanged: (message: string) => void
  onError: (message: string) => void
}

const SOURCE_LABELS: Record<string, string> = {
  upload: 'загрузка',
  edit: 'редактирование',
  office: 'ONLYOFFICE',
  restore: 'восстановление',
  move: 'перемещение',
}

function formatDate(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('ru-RU')
}

function formatSize(size: number): string {
  if (size < 1024) return `${size} Б`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} КБ`
  return `${(size / (1024 * 1024)).toFixed(1)} МБ`
}

export default function VersionHistoryDialog({ open, path, name, onClose, onChanged, onError }: Props) {
  const queryClient = useQueryClient()
  const [busy, setBusy] = useState(false)

  const { data, isLoading, isError } = useQuery({
    queryKey: storageKeys.versions(path ?? ''),
    queryFn: () => fetchVersions(path as string),
    enabled: open && !!path,
  })

  const versions: StorageVersion[] = data ?? []

  const restore = async (version: number) => {
    if (!path) return
    setBusy(true)
    try {
      await restoreVersion(path, version)
      queryClient.invalidateQueries({ queryKey: storageKeys.versions(path) })
      queryClient.invalidateQueries({ queryKey: ['storage', 'list'] })
      queryClient.invalidateQueries({ queryKey: storageKeys.quota() })
      onChanged(`Восстановлена версия ${version}`)
    } catch (e: any) {
      onError(e?.response?.data?.message || 'Не удалось восстановить версию')
    } finally {
      setBusy(false)
    }
  }

  const remove = async (version: number) => {
    if (!path) return
    setBusy(true)
    try {
      await deleteVersion(path, version)
      queryClient.invalidateQueries({ queryKey: storageKeys.versions(path) })
      queryClient.invalidateQueries({ queryKey: storageKeys.quota() })
      onChanged('Версия удалена')
    } catch (e: any) {
      onError(e?.response?.data?.message || 'Не удалось удалить версию')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle>История версий «{name}»</DialogTitle>
      <DialogContent dividers>
        {isLoading && <CircularProgress size={22} />}
        {isError && <Alert severity="error">Не удалось загрузить историю версий</Alert>}
        {!isLoading && versions.length === 0 && <Alert severity="info">Версий пока нет</Alert>}

        {versions.length > 0 && (
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Версия</TableCell>
                <TableCell>Источник</TableCell>
                <TableCell>Размер</TableCell>
                <TableCell>Дата</TableCell>
                <TableCell align="right" />
              </TableRow>
            </TableHead>
            <TableBody>
              {versions.map((version) => (
                <TableRow key={version.id}>
                  <TableCell>v{version.version}</TableCell>
                  <TableCell>{SOURCE_LABELS[version.source] ?? version.source}</TableCell>
                  <TableCell>{formatSize(version.size)}</TableCell>
                  <TableCell>{formatDate(version.created_at)}</TableCell>
                  <TableCell align="right">
                    <Tooltip title="Скачать">
                      <span>
                        <IconButton
                          size="small"
                          disabled={busy || version.status !== 'ready'}
                          onClick={() => path && downloadVersion(path, version.version, name)}
                        >
                          <DownloadIcon fontSize="small" />
                        </IconButton>
                      </span>
                    </Tooltip>
                    <Tooltip title="Восстановить">
                      <span>
                        <IconButton
                          size="small"
                          disabled={busy || version.status !== 'ready'}
                          onClick={() => restore(version.version)}
                        >
                          <RestoreIcon fontSize="small" />
                        </IconButton>
                      </span>
                    </Tooltip>
                    <Tooltip title="Удалить версию">
                      <span>
                        <IconButton size="small" disabled={busy} onClick={() => remove(version.version)}>
                          <DeleteIcon fontSize="small" />
                        </IconButton>
                      </span>
                    </Tooltip>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </DialogContent>
      <DialogActions>
        <Typography sx={{ flex: 1, pl: 2, fontSize: '0.75rem', color: 'text.secondary' }}>
          Хранится до 20 версий / 30 дней
        </Typography>
        <Button onClick={onClose} sx={{ textTransform: 'none' }}>
          Закрыть
        </Button>
      </DialogActions>
    </Dialog>
  )
}
