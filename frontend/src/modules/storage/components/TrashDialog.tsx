import { useState } from 'react'
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  IconButton,
  InputLabel,
  MenuItem,
  Select,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import { DeleteForever as PurgeIcon, Restore as RestoreIcon } from '@mui/icons-material'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { emptyTrash, fetchTrash, purgeTrashItem, restoreTrashItem, storageKeys } from '../api/storageApi'
import type { StorageTrashItem } from '../types'

interface Props {
  open: boolean
  onClose: () => void
  onChanged: (message: string) => void
  onError: (message: string) => void
}

function formatSize(size: number): string {
  if (size < 1024) return `${size} Б`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} КБ`
  return `${(size / (1024 * 1024)).toFixed(1)} МБ`
}

export default function TrashDialog({ open, onClose, onChanged, onError }: Props) {
  const queryClient = useQueryClient()
  const [query, setQuery] = useState('')
  const [strategy, setStrategy] = useState<'rename' | 'overwrite' | 'fail'>('rename')
  const [busy, setBusy] = useState(false)

  const { data, isLoading, isError } = useQuery({
    queryKey: storageKeys.trash({ q: query }),
    queryFn: () => fetchTrash({ q: query }),
    enabled: open,
  })

  const items: StorageTrashItem[] = data?.data ?? []

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['storage', 'trash'] })
    queryClient.invalidateQueries({ queryKey: ['storage', 'list'] })
    queryClient.invalidateQueries({ queryKey: storageKeys.quota() })
  }

  const restore = async (id: number) => {
    setBusy(true)
    try {
      await restoreTrashItem(id, strategy)
      refresh()
      onChanged('Восстановлено из корзины')
    } catch (e: any) {
      onError(e?.response?.data?.message || 'Не удалось восстановить')
    } finally {
      setBusy(false)
    }
  }

  const purge = async (id: number) => {
    if (!window.confirm('Удалить безвозвратно?')) return
    setBusy(true)
    try {
      await purgeTrashItem(id)
      refresh()
      onChanged('Удалено безвозвратно')
    } catch (e: any) {
      onError(e?.response?.data?.message || 'Не удалось удалить')
    } finally {
      setBusy(false)
    }
  }

  const empty = useMutation({
    mutationFn: emptyTrash,
    onSuccess: (count) => {
      refresh()
      onChanged(`Очищено записей: ${count}`)
    },
    onError: () => onError('Не удалось очистить корзину'),
  })

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle>Корзина</DialogTitle>
      <DialogContent dividers>
        <Box sx={{ display: 'flex', gap: 2, mb: 2, flexWrap: 'wrap' }}>
          <TextField
            size="small"
            label="Поиск по пути"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            sx={{ minWidth: 240 }}
          />
          <FormControl size="small" sx={{ minWidth: 200 }}>
            <InputLabel>При конфликте</InputLabel>
            <Select label="При конфликте" value={strategy} onChange={(e) => setStrategy(e.target.value as any)}>
              <MenuItem value="rename">Переименовать</MenuItem>
              <MenuItem value="overwrite">Перезаписать</MenuItem>
              <MenuItem value="fail">Не восстанавливать</MenuItem>
            </Select>
          </FormControl>
        </Box>

        {isLoading && <CircularProgress size={22} />}
        {isError && <Alert severity="error">Не удалось загрузить корзину</Alert>}
        {!isLoading && items.length === 0 && <Alert severity="info">Корзина пуста</Alert>}

        {items.length > 0 && (
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Путь</TableCell>
                <TableCell>Тип</TableCell>
                <TableCell>Размер</TableCell>
                <TableCell>Удалён</TableCell>
                <TableCell align="right" />
              </TableRow>
            </TableHead>
            <TableBody>
              {items.map((item) => (
                <TableRow key={item.id}>
                  <TableCell>
                    <Typography sx={{ fontSize: '0.85rem' }} noWrap title={item.path}>
                      {item.path}
                    </Typography>
                  </TableCell>
                  <TableCell>{item.type === 'dir' ? 'Папка' : 'Файл'}</TableCell>
                  <TableCell>{formatSize(item.size)}</TableCell>
                  <TableCell>{item.deleted_at ? new Date(item.deleted_at).toLocaleString('ru-RU') : ''}</TableCell>
                  <TableCell align="right">
                    <Tooltip title="Восстановить">
                      <span>
                        <IconButton size="small" disabled={busy} onClick={() => restore(item.id)}>
                          <RestoreIcon fontSize="small" />
                        </IconButton>
                      </span>
                    </Tooltip>
                    <Tooltip title="Удалить безвозвратно">
                      <span>
                        <IconButton size="small" disabled={busy} onClick={() => purge(item.id)}>
                          <PurgeIcon fontSize="small" />
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
        <Button
          color="error"
          disabled={items.length === 0 || empty.isPending}
          onClick={() => empty.mutate()}
          sx={{ textTransform: 'none' }}
        >
          Очистить корзину
        </Button>
        <Box sx={{ flex: 1 }} />
        <Button onClick={onClose} variant="contained" sx={{ textTransform: 'none' }}>
          Закрыть
        </Button>
      </DialogActions>
    </Dialog>
  )
}
