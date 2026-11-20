import { useEffect, useState } from 'react'
import { Alert, Box, Button, Chip, IconButton, List, ListItem, ListItemText, Paper, Typography } from '@mui/material'
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline'
import apiClient from '@/modules/shared/api/apiClient'

interface Session {
  id: number
  name: string
  last_used_at?: string | null
  created_at?: string | null
  current: boolean
}

export default function SessionsCard() {
  const [sessions, setSessions] = useState<Session[]>([])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const load = async () => {
    try {
      const response = await apiClient.get('/v1/auth/sessions')
      setSessions(response.data?.data ?? [])
    } catch {
      setError('Не удалось загрузить сессии')
    }
  }

  useEffect(() => {
    void load()
  }, [])

  const revoke = async (id: number) => {
    setBusy(true)
    try {
      await apiClient.delete(`/v1/auth/sessions/${id}`)
      await load()
    } finally {
      setBusy(false)
    }
  }

  const revokeOthers = async () => {
    setBusy(true)
    try {
      await apiClient.delete('/v1/auth/sessions')
      await load()
    } finally {
      setBusy(false)
    }
  }

  const format = (value?: string | null) => (value ? new Date(value).toLocaleString() : '—')

  return (
    <Paper sx={{ p: 3, mt: 3 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1 }}>
        <Typography variant="h6">Активные сессии</Typography>
        <Button size="small" onClick={revokeOthers} disabled={busy || sessions.length <= 1}>
          Завершить другие сессии
        </Button>
      </Box>

      {error && (
        <Alert severity="error" sx={{ mb: 2 }}>
          {error}
        </Alert>
      )}

      <List dense>
        {sessions.map((session) => (
          <ListItem
            key={session.id}
            secondaryAction={
              <IconButton edge="end" onClick={() => revoke(session.id)} disabled={busy} aria-label="Завершить сессию">
                <DeleteOutlineIcon fontSize="small" />
              </IconButton>
            }
          >
            <ListItemText
              primary={
                <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                  {session.name || 'Сессия'}
                  {session.current && <Chip size="small" color="primary" label="Текущая" />}
                </Box>
              }
              secondary={`Создана: ${format(session.created_at)} · активность: ${format(session.last_used_at)}`}
            />
          </ListItem>
        ))}
      </List>
    </Paper>
  )
}
