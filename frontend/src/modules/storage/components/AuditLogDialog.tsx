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
  MenuItem,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material'
import { useQuery } from '@tanstack/react-query'
import { fetchAdminAudit, fetchAudit, storageKeys } from '../api/storageApi'
import type { StorageAuditEntry } from '../types'

interface Props {
  open: boolean
  onClose: () => void
  admin?: boolean
}

const ACTION_LABELS: Record<string, string> = {
  'file.upload': 'Загрузка',
  'file.upload.chunked': 'Загрузка (частями)',
  'file.download': 'Скачивание',
  'file.view': 'Просмотр',
  'file.edit': 'Редактирование',
  'file.move': 'Перемещение',
  'file.create': 'Создание',
  'file.trash': 'В корзину',
  'file.restore': 'Восстановление',
  'file.purge': 'Безвозвратное удаление',
  'file.discovered': 'Обнаружен',
  'version.create': 'Версия создана',
  'version.restore': 'Версия восстановлена',
  'version.delete': 'Версия удалена',
  'share.create': 'Ссылка создана',
  'share.revoke': 'Ссылка отозвана',
  'share.access': 'Доступ по ссылке',
  'share.denied': 'Отказ по ссылке',
  'office.save': 'Сохранение ONLYOFFICE',
  'quota.exceeded': 'Превышение квоты',
  'admin.quota.update': 'Изменение квоты',
  'admin.settings.update': 'Изменение настроек',
}

export default function AuditLogDialog({ open, onClose, admin }: Props) {
  const [action, setAction] = useState('')
  const [path, setPath] = useState('')
  const [page, setPage] = useState(1)

  const filters = { action, path, page, per_page: 25 }

  const { data, isLoading, isError } = useQuery({
    queryKey: admin ? storageKeys.adminAudit(filters) : storageKeys.audit(filters),
    queryFn: () => (admin ? fetchAdminAudit(filters) : fetchAudit(filters)),
    enabled: open,
  })

  const entries: StorageAuditEntry[] = data?.data ?? []
  const meta = data?.meta ?? {}
  const lastPage = meta.last_page ?? 1

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="lg">
      <DialogTitle>Журнал доступа{admin ? ' (все пользователи)' : ''}</DialogTitle>
      <DialogContent dividers>
        <Box sx={{ display: 'flex', gap: 2, mb: 2, flexWrap: 'wrap' }}>
          <TextField
            select
            size="small"
            label="Действие"
            value={action}
            onChange={(e) => {
              setAction(e.target.value)
              setPage(1)
            }}
            sx={{ minWidth: 220 }}
          >
            <MenuItem value="">Все</MenuItem>
            {Object.entries(ACTION_LABELS).map(([value, label]) => (
              <MenuItem key={value} value={value}>
                {label}
              </MenuItem>
            ))}
          </TextField>
          <TextField
            size="small"
            label="Путь"
            value={path}
            onChange={(e) => {
              setPath(e.target.value)
              setPage(1)
            }}
            sx={{ minWidth: 260 }}
          />
        </Box>

        {isLoading && <CircularProgress size={22} />}
        {isError && <Alert severity="error">Не удалось загрузить журнал</Alert>}
        {!isLoading && entries.length === 0 && <Alert severity="info">Записей нет</Alert>}

        {entries.length > 0 && (
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Дата</TableCell>
                {admin && <TableCell>Пользователь</TableCell>}
                <TableCell>Действие</TableCell>
                <TableCell>Путь</TableCell>
                <TableCell>IP</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {entries.map((entry) => (
                <TableRow key={entry.id}>
                  <TableCell sx={{ whiteSpace: 'nowrap' }}>
                    {entry.created_at ? new Date(entry.created_at).toLocaleString('ru-RU') : ''}
                  </TableCell>
                  {admin && <TableCell>{entry.user_id ?? '—'}</TableCell>}
                  <TableCell>{ACTION_LABELS[entry.action] ?? entry.action}</TableCell>
                  <TableCell>
                    <Typography sx={{ fontSize: '0.8rem' }} noWrap title={entry.path ?? ''}>
                      {entry.path ?? '—'}
                    </Typography>
                  </TableCell>
                  <TableCell>{entry.ip ?? '—'}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </DialogContent>
      <DialogActions>
        <Typography sx={{ flex: 1, pl: 2, fontSize: '0.75rem', color: 'text.secondary' }}>
          Страница {page} из {lastPage} · всего {meta.total ?? 0}
        </Typography>
        <Button disabled={page <= 1} onClick={() => setPage((p) => p - 1)} sx={{ textTransform: 'none' }}>
          Назад
        </Button>
        <Button disabled={page >= lastPage} onClick={() => setPage((p) => p + 1)} sx={{ textTransform: 'none' }}>
          Вперёд
        </Button>
        <Button onClick={onClose} variant="contained" sx={{ textTransform: 'none' }}>
          Закрыть
        </Button>
      </DialogActions>
    </Dialog>
  )
}
