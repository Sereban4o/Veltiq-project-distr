import { useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Divider,
  FormControl,
  IconButton,
  InputLabel,
  LinearProgress,
  MenuItem,
  Select,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import { Delete as DeleteIcon, Add as AddIcon } from '@mui/icons-material'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link as RouterLink } from 'react-router-dom'
import apiClient from '@/modules/shared/api/apiClient'
import type { IssueLinkRef } from '@/types'

const LINK_TYPES: { value: string; label: string }[] = [
  { value: 'relates_to', label: 'Связана с' },
  { value: 'blocks', label: 'Блокирует' },
  { value: 'is_blocked_by', label: 'Блокируется' },
  { value: 'duplicates', label: 'Дублирует' },
  { value: 'is_duplicated_by', label: 'Дублируется' },
  { value: 'clones', label: 'Клонирует' },
  { value: 'is_cloned_by', label: 'Клонируется' },
]

const TYPE_LABELS: Record<string, string> = LINK_TYPES.reduce<Record<string, string>>(
  (acc, item) => ({ ...acc, [item.value]: item.label }),
  {},
)

export default function LinksPanel({ issueId }: { issueId: string }) {
  const queryClient = useQueryClient()
  const [linkType, setLinkType] = useState('relates_to')
  const [key, setKey] = useState('')
  const [error, setError] = useState('')

  const { data: links, isLoading } = useQuery<IssueLinkRef[]>({
    queryKey: ['issue-links', issueId],
    queryFn: async () => apiClient.get(`/v1/tasksbase/issues/${issueId}/links`).then((r) => r.data),
    enabled: !!issueId,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['issue-links', issueId] })

  const addLink = useMutation({
    mutationFn: async () => {
      const res = await apiClient.get('/v1/tasksbase/issues', {
        params: { key: key.trim().toUpperCase(), per_page: 1 },
      })
      const items = res.data?.data ?? res.data ?? []
      const target = Array.isArray(items) ? items[0] : null
      if (!target) throw new Error('Задача с таким ключом не найдена')
      return apiClient.post(`/v1/tasksbase/issues/${issueId}/links`, {
        target_issue_id: target.id,
        link_type: linkType,
      })
    },
    onSuccess: () => {
      setKey('')
      setError('')
      invalidate()
    },
    onError: (err: any) => setError(err?.response?.data?.message || err?.message || 'Не удалось добавить связь'),
  })

  const removeLink = useMutation({
    mutationFn: (linkId: number) => apiClient.delete(`/v1/tasksbase/issues/${issueId}/links/${linkId}`),
    onSuccess: invalidate,
  })

  return (
    <Card>
      <CardContent>
        <Typography variant="h6" gutterBottom>
          Связи
        </Typography>

        {isLoading && <LinearProgress />}

        {!isLoading && (links?.length ?? 0) === 0 && (
          <Typography variant="body2" color="text.secondary">
            Связей пока нет
          </Typography>
        )}

        <Stack divider={<Divider flexItem />} spacing={1} sx={{ my: 1 }}>
          {links?.map((link) => (
            <Stack key={link.id} direction="row" alignItems="center" spacing={1}>
              <Chip size="small" label={TYPE_LABELS[link.type] || link.type} />
              {link.issue ? (
                <Typography
                  component={RouterLink}
                  to={`/issues/${link.issue.id}`}
                  variant="body2"
                  sx={{ flexGrow: 1, textDecoration: 'none', color: 'primary.main' }}
                >
                  {link.issue.key} · {link.issue.summary}
                </Typography>
              ) : (
                <Box sx={{ flexGrow: 1 }} />
              )}
              {link.issue?.status && (
                <Chip
                  size="small"
                  label={link.issue.status.name}
                  sx={{ bgcolor: link.issue.status.color || undefined }}
                />
              )}
              <IconButton size="small" onClick={() => removeLink.mutate(link.id)}>
                <DeleteIcon fontSize="small" />
              </IconButton>
            </Stack>
          ))}
        </Stack>

        {error && (
          <Alert severity="error" sx={{ mb: 1 }} onClose={() => setError('')}>
            {error}
          </Alert>
        )}

        <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
          <FormControl size="small" sx={{ minWidth: 160 }}>
            <InputLabel>Тип связи</InputLabel>
            <Select label="Тип связи" value={linkType} onChange={(e) => setLinkType(e.target.value)}>
              {LINK_TYPES.map((type) => (
                <MenuItem key={type.value} value={type.value}>
                  {type.label}
                </MenuItem>
              ))}
            </Select>
          </FormControl>
          <TextField
            size="small"
            fullWidth
            placeholder="Ключ задачи, напр. PROJ-12"
            value={key}
            onChange={(e) => setKey(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && key.trim()) addLink.mutate()
            }}
          />
          <Button
            variant="contained"
            startIcon={<AddIcon />}
            disabled={!key.trim() || addLink.isPending}
            onClick={() => addLink.mutate()}
          >
            Связать
          </Button>
        </Stack>
      </CardContent>
    </Card>
  )
}
