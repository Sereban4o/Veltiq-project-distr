import { useEffect, useMemo, useState } from 'react'
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Box,
  Stack,
  Typography,
  Autocomplete,
  TextField,
  IconButton,
  Select,
  MenuItem,
  CircularProgress,
} from '@mui/material'
import { Close as CloseIcon, Delete as DeleteIcon } from '@mui/icons-material'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import knowledgebaseApi from '../api/knowledgebaseApi'
import type { User } from '@/types'

interface Entry {
  user_id: number
  permission: 'viewer' | 'editor'
  name: string
}

interface PagePermissionsDialogProps {
  open: boolean
  onClose: () => void
  pageId: number
}

export default function PagePermissionsDialog({ open, onClose, pageId }: PagePermissionsDialogProps) {
  const queryClient = useQueryClient()
  const [entries, setEntries] = useState<Entry[]>([])

  const { data: permissionsData, isLoading } = useQuery({
    queryKey: ['page-permissions', pageId],
    queryFn: () => knowledgebaseApi.getPermissions(pageId),
    enabled: open && !!pageId,
  })

  const { data: users } = useQuery<User[]>({
    queryKey: ['users-all'],
    queryFn: async () => {
      const response = await apiClient.get('/v1/users/all')
      const data = response.data
      return Array.isArray(data) ? data : data?.data || []
    },
    enabled: open,
  })

  useEffect(() => {
    if (permissionsData?.permissions) {
      setEntries(
        permissionsData.permissions.map((p) => ({
          user_id: p.user_id,
          permission: p.permission,
          name: p.user?.full_name || `#${p.user_id}`,
        })),
      )
    }
  }, [permissionsData])

  const saveMutation = useMutation({
    mutationFn: () =>
      knowledgebaseApi.updatePermissions(
        pageId,
        entries.map((e) => ({ user_id: e.user_id, permission: e.permission })),
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['page-permissions', pageId] })
      onClose()
    },
  })

  const availableUsers = useMemo(
    () => (users || []).filter((u) => !entries.some((e) => e.user_id === u.id)),
    [users, entries],
  )

  const addUser = (user: User | null) => {
    if (!user) return
    setEntries((prev) => [...prev, { user_id: user.id, permission: 'viewer', name: user.full_name || user.email }])
  }

  const removeEntry = (userId: number) => setEntries((prev) => prev.filter((e) => e.user_id !== userId))

  const changePermission = (userId: number, permission: 'viewer' | 'editor') =>
    setEntries((prev) => prev.map((e) => (e.user_id === userId ? { ...e, permission } : e)))

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        Доступ к странице
        <IconButton onClick={onClose} size="small">
          <CloseIcon />
        </IconButton>
      </DialogTitle>
      <DialogContent dividers>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
          Права распространяются на дочерние страницы. Пользователи, не указанные здесь, видят страницу согласно правам
          пространства.
        </Typography>

        {isLoading ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', py: 3 }}>
            <CircularProgress />
          </Box>
        ) : (
          <>
            <Autocomplete
              options={availableUsers}
              getOptionLabel={(option) => option.full_name || option.email}
              onChange={(_e, value) => addUser(value)}
              value={null}
              renderInput={(params) => <TextField {...params} label="Добавить пользователя" size="small" />}
              sx={{ mb: 2 }}
            />

            <Stack spacing={1}>
              {entries.length === 0 && (
                <Typography variant="body2" color="text.secondary">
                  Нет персональных прав — используются права пространства.
                </Typography>
              )}
              {entries.map((entry) => (
                <Stack
                  key={entry.user_id}
                  direction="row"
                  alignItems="center"
                  spacing={1}
                  sx={{ p: 1, border: '1px solid', borderColor: 'divider', borderRadius: 1 }}
                >
                  <Typography variant="body2" sx={{ flexGrow: 1, overflow: 'hidden', textOverflow: 'ellipsis' }}>
                    {entry.name}
                  </Typography>
                  <Select
                    size="small"
                    value={entry.permission}
                    onChange={(e) => changePermission(entry.user_id, e.target.value as 'viewer' | 'editor')}
                  >
                    <MenuItem value="viewer">Просмотр</MenuItem>
                    <MenuItem value="editor">Редактирование</MenuItem>
                  </Select>
                  <IconButton size="small" color="error" onClick={() => removeEntry(entry.user_id)}>
                    <DeleteIcon fontSize="small" />
                  </IconButton>
                </Stack>
              ))}
            </Stack>
          </>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Отмена</Button>
        <Button variant="contained" onClick={() => saveMutation.mutate()} disabled={saveMutation.isPending}>
          Сохранить
        </Button>
      </DialogActions>
    </Dialog>
  )
}
