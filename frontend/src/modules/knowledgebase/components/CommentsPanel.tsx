import { useState } from 'react'
import { Box, Typography, TextField, Button, Stack, IconButton, Divider, Chip, CircularProgress } from '@mui/material'
import { Delete as DeleteIcon, CheckCircle as ResolveIcon, Replay as ReopenIcon } from '@mui/icons-material'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import knowledgebaseApi from '../api/knowledgebaseApi'
import type { PageComment } from '@/types'

interface CommentsPanelProps {
  pageId: number
  canComment: boolean
}

export default function CommentsPanel({ pageId, canComment }: CommentsPanelProps) {
  const queryClient = useQueryClient()
  const [body, setBody] = useState('')

  const { data: comments, isLoading } = useQuery<PageComment[]>({
    queryKey: ['page-comments', pageId],
    queryFn: () => knowledgebaseApi.getComments(pageId),
    enabled: !!pageId,
  })

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['page-comments', pageId] })

  const addMutation = useMutation({
    mutationFn: (text: string) => knowledgebaseApi.addComment(pageId, text),
    onSuccess: () => {
      setBody('')
      invalidate()
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (commentId: number) => knowledgebaseApi.deleteComment(pageId, commentId),
    onSuccess: invalidate,
  })

  const resolveMutation = useMutation({
    mutationFn: ({ commentId, resolved }: { commentId: number; resolved: boolean }) =>
      knowledgebaseApi.resolveComment(pageId, commentId, resolved),
    onSuccess: invalidate,
  })

  const handleAdd = () => {
    if (body.trim()) addMutation.mutate(body.trim())
  }

  return (
    <Box>
      <Typography variant="h6" sx={{ mb: 1 }}>
        Комментарии{comments && comments.length > 0 ? ` (${comments.length})` : ''}
      </Typography>

      {isLoading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 2 }}>
          <CircularProgress size={20} />
        </Box>
      ) : !comments || comments.length === 0 ? (
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
          Комментариев пока нет
        </Typography>
      ) : (
        <Stack spacing={1.5} sx={{ mb: 2 }}>
          {comments.map((comment) => (
            <Box
              key={comment.id}
              sx={{
                p: 1.5,
                borderRadius: 1,
                bgcolor: comment.resolved ? 'action.hover' : 'background.paper',
                border: '1px solid',
                borderColor: 'divider',
              }}
            >
              <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 0.5 }}>
                <Typography variant="subtitle2">{comment.user?.full_name || 'Пользователь'}</Typography>
                <Typography variant="caption" color="text.secondary">
                  {new Date(comment.created_at).toLocaleString()}
                </Typography>
                {comment.resolved && <Chip label="Решён" size="small" color="success" />}
                <Box sx={{ flexGrow: 1 }} />
                <IconButton
                  size="small"
                  title={comment.resolved ? 'Переоткрыть' : 'Решить'}
                  onClick={() => resolveMutation.mutate({ commentId: comment.id, resolved: !comment.resolved })}
                >
                  {comment.resolved ? <ReopenIcon fontSize="small" /> : <ResolveIcon fontSize="small" />}
                </IconButton>
                <IconButton
                  size="small"
                  color="error"
                  title="Удалить"
                  onClick={() => deleteMutation.mutate(comment.id)}
                >
                  <DeleteIcon fontSize="small" />
                </IconButton>
              </Stack>
              <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>
                {comment.body}
              </Typography>
            </Box>
          ))}
        </Stack>
      )}

      <Divider sx={{ mb: 2 }} />

      {canComment ? (
        <Stack spacing={1}>
          <TextField
            multiline
            minRows={2}
            fullWidth
            size="small"
            placeholder="Оставить комментарий..."
            value={body}
            onChange={(e) => setBody(e.target.value)}
          />
          <Box>
            <Button
              variant="contained"
              size="small"
              onClick={handleAdd}
              disabled={!body.trim() || addMutation.isPending}
            >
              Отправить
            </Button>
          </Box>
        </Stack>
      ) : (
        <Typography variant="body2" color="text.secondary">
          У вас нет прав оставлять комментарии
        </Typography>
      )}
    </Box>
  )
}
