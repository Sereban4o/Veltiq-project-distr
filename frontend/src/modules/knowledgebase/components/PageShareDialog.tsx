import { useEffect, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  Divider,
  IconButton,
  List,
  ListItem,
  ListItemText,
  MenuItem,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import ContentCopyIcon from '@mui/icons-material/ContentCopy'
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline'
import { knowledgebaseApi, type PageShare, type PageShareCreated } from '../api/knowledgebaseApi'

interface PageShareDialogProps {
  open: boolean
  pageId: number
  onClose: () => void
}

const EXPIRY_OPTIONS = [
  { value: 0, label: 'Без ограничения' },
  { value: 1, label: '1 день' },
  { value: 7, label: '7 дней' },
  { value: 30, label: '30 дней' },
]

export default function PageShareDialog({ open, pageId, onClose }: PageShareDialogProps) {
  const [shares, setShares] = useState<PageShare[]>([])
  const [expiresDays, setExpiresDays] = useState(7)
  const [password, setPassword] = useState('')
  const [created, setCreated] = useState<PageShareCreated | null>(null)
  const [loading, setLoading] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  const reload = async () => {
    setLoading(true)
    try {
      setShares(await knowledgebaseApi.pageShares(pageId))
    } catch {
      setError('Не удалось загрузить ссылки')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (!open) return
    setError('')
    setCreated(null)
    setPassword('')
    void reload()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, pageId])

  const create = async () => {
    setBusy(true)
    setError('')
    try {
      const expiresAt = expiresDays > 0 ? new Date(Date.now() + expiresDays * 86400_000).toISOString() : null
      const share = await knowledgebaseApi.createPageShare(pageId, {
        expires_at: expiresAt,
        password: password.trim() || null,
      })
      setCreated(share)
      setPassword('')
      await reload()
    } catch {
      setError('Не удалось создать ссылку')
    } finally {
      setBusy(false)
    }
  }

  const revoke = async (shareId: number) => {
    setBusy(true)
    try {
      await knowledgebaseApi.revokePageShare(shareId)
      await reload()
    } catch {
      setError('Не удалось отозвать ссылку')
    } finally {
      setBusy(false)
    }
  }

  const copy = async () => {
    if (!created) return
    try {
      await navigator.clipboard.writeText(`${window.location.origin}${created.path}`)
    } catch {
      // Clipboard may be unavailable (insecure context); the link stays visible.
    }
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle>Публичная ссылка на страницу</DialogTitle>
      <DialogContent>
        {error && (
          <Alert severity="error" sx={{ mb: 2 }}>
            {error}
          </Alert>
        )}

        <Stack direction="row" spacing={2} alignItems="center" sx={{ mb: 2 }}>
          <TextField
            select
            size="small"
            label="Срок действия"
            value={expiresDays}
            onChange={(e) => setExpiresDays(Number(e.target.value))}
            sx={{ minWidth: 180 }}
          >
            {EXPIRY_OPTIONS.map((option) => (
              <MenuItem key={option.value} value={option.value}>
                {option.label}
              </MenuItem>
            ))}
          </TextField>
          <TextField
            size="small"
            label="Пароль (необязательно)"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          <Button variant="contained" onClick={create} disabled={busy}>
            Создать ссылку
          </Button>
        </Stack>

        {created && (
          <Alert severity="success" sx={{ mb: 2 }}>
            <Stack direction="row" alignItems="center" spacing={1}>
              <Typography variant="body2" sx={{ wordBreak: 'break-all', flex: 1 }}>
                {window.location.origin}
                {created.path}
              </Typography>
              <IconButton size="small" onClick={copy} aria-label="Скопировать ссылку">
                <ContentCopyIcon fontSize="small" />
              </IconButton>
            </Stack>
          </Alert>
        )}

        <Divider sx={{ my: 2 }} />

        <Typography variant="subtitle2" sx={{ mb: 1 }}>
          Существующие ссылки
        </Typography>

        {loading ? (
          <Typography variant="body2" color="text.secondary">
            Загрузка…
          </Typography>
        ) : shares.length === 0 ? (
          <Typography variant="body2" color="text.secondary">
            Ссылок пока нет
          </Typography>
        ) : (
          <List dense>
            {shares.map((share) => (
              <ListItem
                key={share.id}
                secondaryAction={
                  share.active ? (
                    <IconButton edge="end" onClick={() => revoke(share.id)} disabled={busy} aria-label="Отозвать">
                      <DeleteOutlineIcon fontSize="small" />
                    </IconButton>
                  ) : (
                    <Typography variant="caption" color="text.secondary">
                      отозвана
                    </Typography>
                  )
                }
              >
                <ListItemText
                  primary={`Создана ${share.created_at ? new Date(share.created_at).toLocaleString() : '—'}`}
                  secondary={[
                    share.has_password ? 'пароль' : null,
                    share.expires_at ? `до ${new Date(share.expires_at).toLocaleString()}` : 'бессрочная',
                  ]
                    .filter(Boolean)
                    .join(' · ')}
                />
              </ListItem>
            ))}
          </List>
        )}

        <Box sx={{ mt: 1 }}>
          <Typography variant="caption" color="text.secondary">
            Ссылка открывает страницу только для чтения без входа в систему.
          </Typography>
        </Box>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Закрыть</Button>
      </DialogActions>
    </Dialog>
  )
}
