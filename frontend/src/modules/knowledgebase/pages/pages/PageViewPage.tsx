import { useParams, useNavigate, Link as RouterLink } from 'react-router-dom'
import { useRef, useState, useMemo } from 'react'
import {
  Box,
  Typography,
  Button,
  Chip,
  Stack,
  Divider,
  IconButton,
  List,
  ListItem,
  ListItemText,
  ListItemIcon,
  Tooltip,
  LinearProgress,
  Alert,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  CircularProgress,
} from '@mui/material'
import {
  ArrowBack as ArrowBackIcon,
  Edit as EditIcon,
  AttachFile as AttachFileIcon,
  Delete as DeleteIcon,
  Download as DownloadIcon,
  InsertDriveFile as FileIcon,
  Share as ShareIcon,
  AutoAwesome as SummaryIcon,
} from '@mui/icons-material'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import LoadingSpinner from '@/modules/shared/components/LoadingSpinner'
import PageShareDialog from '@/modules/knowledgebase/components/PageShareDialog'
import { knowledgebaseApi, type PageBacklink } from '@/modules/knowledgebase/api/knowledgebaseApi'
import type { PageAttachment } from '@/types'

function formatSize(bytes?: number): string {
  if (!bytes || bytes <= 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  const value = bytes / Math.pow(1024, i)
  return `${i === 0 ? value : value.toFixed(1)} ${units[i]}`
}

function withFileTokens(html: string, token: string): string {
  if (!token) return html
  return html.replace(/(\/api\/v1\/files\/\d+)(?!\?)/g, (_match, url) => `${url}?token=${encodeURIComponent(token)}`)
}

function withHeadingAnchors(html: string): { html: string; toc: { id: string; text: string; level: number }[] } {
  const toc: { id: string; text: string; level: number }[] = []
  let index = 0

  const withAnchors = html.replace(/<(h[1-3])([^>]*)>([\s\S]*?)<\/\1>/gi, (_match, tag, attrs, inner) => {
    const text = String(inner)
      .replace(/<[^>]+>/g, '')
      .trim()
    if (!text) return _match
    index += 1
    const id = `heading-${index}`
    toc.push({ id, text, level: Number(String(tag)[1]) })
    return `<${tag}${attrs} id="${id}">${inner}</${tag}>`
  })

  return { html: withAnchors, toc }
}

export default function PageView() {
  const { spaceKey, pageId } = useParams<{ spaceKey?: string; pageId?: string }>()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const fileInputRef = useRef<HTMLInputElement>(null)
  const token = typeof localStorage !== 'undefined' ? localStorage.getItem('auth_token') || '' : ''
  const [shareOpen, setShareOpen] = useState(false)
  const [summaryOpen, setSummaryOpen] = useState(false)
  const [summaryBusy, setSummaryBusy] = useState(false)
  const [summaryText, setSummaryText] = useState('')
  const [summaryError, setSummaryError] = useState('')

  const { data: page, isLoading } = useQuery({
    queryKey: ['page', pageId],
    queryFn: async () => apiClient.get(`/v1/knowledgebase/pages/${pageId}`).then((r) => r.data),
    enabled: !!pageId,
  })

  const { data: attachments } = useQuery<PageAttachment[]>({
    queryKey: ['page-attachments', pageId],
    queryFn: async () => apiClient.get(`/v1/knowledgebase/pages/${pageId}/attachments`).then((r) => r.data),
    enabled: !!pageId,
  })

  const { data: backlinks } = useQuery<PageBacklink[]>({
    queryKey: ['page-backlinks', pageId],
    queryFn: () => knowledgebaseApi.pageBacklinks(Number(pageId)),
    enabled: !!pageId,
  })

  const followMutation = useMutation({
    mutationFn: async (follow: boolean) =>
      follow
        ? apiClient.post(`/v1/knowledgebase/pages/${pageId}/follow`)
        : apiClient.delete(`/v1/knowledgebase/pages/${pageId}/follow`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['page', pageId] })
    },
  })

  const { html: bodyHtml, toc } = useMemo(() => {
    const withTokens = withFileTokens(page?.body || '', token)
    return withHeadingAnchors(withTokens)
  }, [page?.body, token])

  const uploadMutation = useMutation({
    mutationFn: async (file: File) => {
      const formData = new FormData()
      formData.append('file', file)
      const uploaded = await apiClient.post('/v1/files/upload', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      return apiClient.post(`/v1/knowledgebase/pages/${pageId}/attachments`, {
        file_id: uploaded.data.id,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['page-attachments', pageId] })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (attachmentId: number) =>
      apiClient.delete(`/v1/knowledgebase/pages/${pageId}/attachments/${attachmentId}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['page-attachments', pageId] })
    },
  })

  const handlePickFile = () => fileInputRef.current?.click()

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (file) uploadMutation.mutate(file)
    e.target.value = ''
  }

  if (isLoading) return <LoadingSpinner message="Загрузка статьи..." />
  if (!page) return <Typography>Статья не найдена</Typography>

  const handleSummarize = async () => {
    if (!pageId) return
    setSummaryOpen(true)
    setSummaryBusy(true)
    setSummaryError('')
    setSummaryText('')
    try {
      const response = await apiClient.post('/v1/ai/summarize', { page_id: Number(pageId) })
      setSummaryText(response.data?.data?.summary ?? '')
    } catch (e: any) {
      setSummaryError(e?.response?.data?.message || 'Не удалось получить резюме')
    } finally {
      setSummaryBusy(false)
    }
  }

  return (
    <Box>
      <Box sx={{ display: 'flex', alignItems: 'center', mb: 3 }}>
        <IconButton onClick={() => navigate(`/knowledgebase/${spaceKey}`)} sx={{ mr: 2 }}>
          <ArrowBackIcon />
        </IconButton>
        <Box sx={{ flexGrow: 1 }}>
          <Typography variant="h4" component="h1">
            {page.title}
          </Typography>
          <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
            <Chip label={`v${page.version}`} size="small" />
            <Chip label={page.status} size="small" color="default" />
            {page.labels?.map((label: string) => (
              <Chip key={label} label={label} size="small" variant="outlined" />
            ))}
          </Stack>
        </Box>
        <Button
          variant="outlined"
          startIcon={<EditIcon />}
          onClick={() => navigate(`/knowledgebase/${spaceKey}/pages/${pageId}/edit`)}
        >
          Edit
        </Button>
        <Button variant="outlined" startIcon={<ShareIcon />} onClick={() => setShareOpen(true)} sx={{ ml: 1 }}>
          Поделиться
        </Button>
        <Button variant="outlined" startIcon={<SummaryIcon />} onClick={handleSummarize} sx={{ ml: 1 }}>
          Резюме
        </Button>
        <Button
          variant="outlined"
          onClick={() => followMutation.mutate(!page?.is_following)}
          disabled={followMutation.isPending}
          sx={{ ml: 1 }}
        >
          {page?.is_following ? 'Не следить' : 'Следить'}
          {typeof page?.followers_count === 'number' ? ` (${page.followers_count})` : ''}
        </Button>
      </Box>

      {pageId && <PageShareDialog open={shareOpen} pageId={Number(pageId)} onClose={() => setShareOpen(false)} />}

      <Dialog open={summaryOpen} onClose={() => setSummaryOpen(false)} fullWidth maxWidth="sm">
        <DialogTitle>Резюме страницы (AI)</DialogTitle>
        <DialogContent>
          {summaryBusy ? (
            <Box sx={{ display: 'flex', justifyContent: 'center', py: 3 }}>
              <CircularProgress size={24} />
            </Box>
          ) : summaryError ? (
            <Alert severity="error">{summaryError}</Alert>
          ) : (
            <Typography sx={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{summaryText}</Typography>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setSummaryOpen(false)}>Закрыть</Button>
        </DialogActions>
      </Dialog>

      <Divider sx={{ mb: 3 }} />

      {toc.length > 1 && (
        <Box sx={{ maxWidth: 900, mb: 2, p: 1.5, bgcolor: 'grey.50', borderRadius: 1 }}>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.5 }}>
            Содержание
          </Typography>
          {toc.map((item) => (
            <Box key={item.id} sx={{ pl: (item.level - 1) * 2 }}>
              <Typography
                variant="body2"
                component="a"
                href={`#${item.id}`}
                sx={{ color: 'primary.main', textDecoration: 'none' }}
              >
                {item.text}
              </Typography>
            </Box>
          ))}
        </Box>
      )}

      {/* Page Content */}
      <Box
        sx={{
          maxWidth: 900,
          '& .tiptap': { maxHeight: '70vh', overflowY: 'auto' },
          '& h1': { fontSize: '2rem', fontWeight: 700, margin: '1rem 0' },
          '& h2': { fontSize: '1.5rem', fontWeight: 600, margin: '0.8rem 0' },
          '& h3': { fontSize: '1.25rem', fontWeight: 500, margin: '0.6rem 0' },
          '& p': { margin: '0.5rem 0', lineHeight: 1.8 },
          '& ul, & ol': { paddingLeft: 2, margin: '0.5rem 0' },
          '& blockquote': {
            borderLeft: '4px solid #dadce0',
            paddingLeft: 2,
            margin: '0.5rem 0',
            color: 'text.secondary',
          },
          '& pre': {
            bgcolor: '#f5f5f5',
            padding: 2,
            borderRadius: 1,
            fontFamily: 'monospace',
            margin: '0.5rem 0',
          },
          '& a': {
            color: 'primary.main',
            textDecoration: 'none',
            '&:hover': {
              textDecoration: 'underline',
            },
          },
        }}
      >
        <Box dangerouslySetInnerHTML={{ __html: bodyHtml }} />
      </Box>

      {/* Attachments */}
      <Box sx={{ maxWidth: 900, mt: 4 }}>
        <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 1 }}>
          <Typography variant="h6">
            Вложения{attachments && attachments.length > 0 ? ` (${attachments.length})` : ''}
          </Typography>
          <Button
            variant="outlined"
            size="small"
            startIcon={<AttachFileIcon />}
            onClick={handlePickFile}
            disabled={uploadMutation.isPending}
          >
            Прикрепить файл
          </Button>
          <input ref={fileInputRef} type="file" hidden onChange={handleFileChange} />
        </Stack>

        {uploadMutation.isPending && <LinearProgress sx={{ mb: 1 }} />}
        {uploadMutation.isError && (
          <Alert severity="error" sx={{ mb: 1 }}>
            Не удалось загрузить файл
          </Alert>
        )}

        {!attachments || attachments.length === 0 ? (
          <Typography variant="body2" color="text.secondary">
            Пока нет вложений. Прикрепите файл к статье.
          </Typography>
        ) : (
          <List disablePadding>
            {attachments.map((attachment) => (
              <ListItem
                key={attachment.id}
                divider
                secondaryAction={
                  <Stack direction="row" spacing={0.5}>
                    <Tooltip title="Скачать">
                      <IconButton
                        component="a"
                        href={`/api/v1/files/${attachment.file_id}/download?token=${encodeURIComponent(token)}`}
                        size="small"
                      >
                        <DownloadIcon fontSize="small" />
                      </IconButton>
                    </Tooltip>
                    <Tooltip title="Удалить">
                      <IconButton
                        size="small"
                        color="error"
                        onClick={() => deleteMutation.mutate(attachment.id)}
                        disabled={deleteMutation.isPending}
                      >
                        <DeleteIcon fontSize="small" />
                      </IconButton>
                    </Tooltip>
                  </Stack>
                }
              >
                <ListItemIcon sx={{ minWidth: 36 }}>
                  <FileIcon fontSize="small" />
                </ListItemIcon>
                <ListItemText
                  primary={attachment.file?.original_name || 'Файл'}
                  secondary={[formatSize(attachment.file?.size), attachment.uploader?.full_name]
                    .filter(Boolean)
                    .join(' · ')}
                />
              </ListItem>
            ))}
          </List>
        )}
      </Box>

      <Divider sx={{ mt: 4, mb: 2 }} />

      {/* Backlinks */}
      {backlinks && backlinks.length > 0 && (
        <Box sx={{ maxWidth: 900, mb: 3 }}>
          <Typography variant="h6" sx={{ mb: 1 }}>
            Ссылаются на эту страницу ({backlinks.length})
          </Typography>
          <List dense>
            {backlinks.map((link) => (
              <ListItem key={link.id}>
                <ListItemIcon sx={{ minWidth: 36 }}>
                  <FileIcon fontSize="small" />
                </ListItemIcon>
                <ListItemText
                  primary={<RouterLink to={link.link}>{link.title}</RouterLink>}
                  secondary={link.space_name}
                />
              </ListItem>
            ))}
          </List>
        </Box>
      )}

      {/* Page Info */}
      <Box sx={{ maxWidth: 900 }}>
        <Typography variant="body2" color="text.secondary">
          Created by {page.author?.full_name || 'Unknown'} on {new Date(page.created_at).toLocaleDateString()}
        </Typography>
        <Typography variant="body2" color="text.secondary">
          Last edited by {page.lastEditor?.full_name || 'Unknown'} on {new Date(page.updated_at).toLocaleDateString()}
        </Typography>
      </Box>
    </Box>
  )
}
