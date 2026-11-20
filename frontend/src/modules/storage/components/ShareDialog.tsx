import { useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Checkbox,
  FormControlLabel,
  IconButton,
  Link,
  List,
  ListItem,
  ListItemText,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import { ContentCopy as CopyIcon, Delete as DeleteIcon } from '@mui/icons-material'
import { Dialog, DialogActions, DialogContent, DialogTitle } from '@mui/material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { createShare, fetchShares, revokeShare, storageKeys } from '../api/storageApi'
import type { StorageShare } from '../types'

interface Props {
  open: boolean
  path: string | null
  name: string
  onClose: () => void
  onChanged: (message: string) => void
  onError: (message: string) => void
}

export default function ShareDialog({ open, path, name, onClose, onChanged, onError }: Props) {
  const queryClient = useQueryClient()
  const [password, setPassword] = useState('')
  const [expiresIn, setExpiresIn] = useState('7')
  const [maxDownloads, setMaxDownloads] = useState('')
  const [canDownload, setCanDownload] = useState(true)
  const [busy, setBusy] = useState(false)
  const [created, setCreated] = useState<StorageShare | null>(null)

  const { data } = useQuery({
    queryKey: storageKeys.shares(path ?? ''),
    queryFn: () => fetchShares(path as string),
    enabled: open && !!path,
  })

  const shares: StorageShare[] = data ?? []

  const copy = (text: string | null) => {
    if (!text) return
    const absolute = text.startsWith('http') ? text : `${window.location.origin}${text}`
    void navigator.clipboard?.writeText(absolute)
    onChanged('Ссылка скопирована')
  }

  const create = async () => {
    if (!path) return
    setBusy(true)
    try {
      const share = await createShare({
        path,
        password: password.trim() || null,
        expires_in: expiresIn ? Number(expiresIn) : null,
        max_downloads: maxDownloads ? Number(maxDownloads) : null,
        can_download: canDownload,
      })
      setCreated(share)
      setPassword('')
      setMaxDownloads('')
      queryClient.invalidateQueries({ queryKey: storageKeys.shares(path) })
      onChanged('Ссылка создана')
    } catch (e: any) {
      onError(e?.response?.data?.message || 'Не удалось создать ссылку')
    } finally {
      setBusy(false)
    }
  }

  const revoke = async (id: number) => {
    if (!path) return
    try {
      await revokeShare(id)
      queryClient.invalidateQueries({ queryKey: storageKeys.shares(path) })
      onChanged('Ссылка отозвана')
    } catch {
      onError('Не удалось отозвать ссылку')
    }
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle>Поделиться «{name}»</DialogTitle>
      <DialogContent dividers>
        <Box sx={{ display: 'flex', gap: 2, flexWrap: 'wrap', mb: 1 }}>
          <TextField
            size="small"
            label="Пароль (необязательно)"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <TextField
            size="small"
            type="number"
            label="Срок, дней"
            value={expiresIn}
            onChange={(e) => setExpiresIn(e.target.value)}
            inputProps={{ min: 1, max: 30 }}
            sx={{ width: 120 }}
          />
          <TextField
            size="small"
            type="number"
            label="Лимит скачиваний"
            value={maxDownloads}
            onChange={(e) => setMaxDownloads(e.target.value)}
            inputProps={{ min: 1 }}
            sx={{ width: 160 }}
          />
        </Box>
        <FormControlLabel
          control={<Checkbox checked={canDownload} onChange={(e) => setCanDownload(e.target.checked)} />}
          label="Разрешить скачивание"
        />

        {created?.token && (
          <Alert
            severity="success"
            sx={{ mt: 1 }}
            action={
              <Tooltip title="Скопировать">
                <IconButton size="small" onClick={() => copy(created.path)}>
                  <CopyIcon fontSize="small" />
                </IconButton>
              </Tooltip>
            }
          >
            <Link component="button" onClick={() => copy(created.path)} sx={{ wordBreak: 'break-all' }}>
              {`${window.location.origin}${created.path ?? ''}`}
            </Link>
          </Alert>
        )}

        <Typography sx={{ mt: 2, fontSize: '0.8rem', fontWeight: 600 }}>Активные ссылки</Typography>
        {shares.length === 0 && (
          <Typography sx={{ fontSize: '0.8rem', color: 'text.secondary' }}>Нет активных ссылок</Typography>
        )}

        <List dense>
          {shares.map((share) => (
            <ListItem
              key={share.id}
              secondaryAction={
                <IconButton size="small" onClick={() => revoke(share.id)}>
                  <DeleteIcon fontSize="small" />
                </IconButton>
              }
            >
              <ListItemText
                primary={share.path ?? '—'}
                secondary={[
                  share.requires_password ? 'пароль' : 'без пароля',
                  share.expires_at ? `до ${new Date(share.expires_at).toLocaleDateString('ru-RU')}` : 'бессрочно',
                  share.max_downloads
                    ? `скачиваний: ${share.downloads_count}/${share.max_downloads}`
                    : `скачиваний: ${share.downloads_count}`,
                ].join(' · ')}
              />
            </ListItem>
          ))}
        </List>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} sx={{ textTransform: 'none' }}>
          Закрыть
        </Button>
        <Button variant="contained" disabled={busy} onClick={create} sx={{ textTransform: 'none' }}>
          Создать ссылку
        </Button>
      </DialogActions>
    </Dialog>
  )
}
