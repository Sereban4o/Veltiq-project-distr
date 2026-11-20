import { useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Divider,
  IconButton,
  MenuItem,
  Paper,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import { Send as SendIcon, AutoAwesome as AssistantIcon, Delete as DeleteIcon } from '@mui/icons-material'
import { Link as RouterLink } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapData } from '@/modules/shared/utils/apiResponse'

interface AiStatus {
  enabled: boolean
  model: string
}

interface AiSource {
  type: string
  id: number
  title: string
  url: string
  snippet: string
}

interface AiAnswer {
  answer: string
  sources: AiSource[]
  model: string
}

interface Turn {
  question: string
  answer?: string
  sources?: AiSource[]
  error?: string
}

const TYPE_LABELS: Record<string, string> = {
  pages: 'База знаний',
  issues: 'Задачи',
  files: 'Файлы',
  mail: 'Почта',
}

export default function AssistantPage() {
  const [question, setQuestion] = useState('')
  const [turns, setTurns] = useState<Turn[]>([])
  const [busy, setBusy] = useState(false)
  const [draftKind, setDraftKind] = useState<'task' | 'mail'>('task')
  const [draftPrompt, setDraftPrompt] = useState('')
  const [draftText, setDraftText] = useState('')
  const [draftBusy, setDraftBusy] = useState(false)
  const [draftError, setDraftError] = useState('')

  const { data: status, isLoading } = useQuery<AiStatus>({
    queryKey: ['ai-status'],
    queryFn: async () => unwrapData<AiStatus>(await apiClient.get('/v1/ai/status')) as AiStatus,
  })

  const ask = async () => {
    const text = question.trim()
    if (!text || busy) return

    setBusy(true)
    setQuestion('')
    const index = turns.length
    setTurns((prev) => [...prev, { question: text }])

    try {
      const data = unwrapData<AiAnswer>(await apiClient.post('/v1/ai/ask', { question: text })) as AiAnswer
      setTurns((prev) =>
        prev.map((turn, i) => (i === index ? { question: text, answer: data.answer, sources: data.sources } : turn)),
      )
    } catch (e: any) {
      const message = e?.response?.data?.message || 'Не удалось получить ответ'
      setTurns((prev) => prev.map((turn, i) => (i === index ? { question: text, error: message } : turn)))
    } finally {
      setBusy(false)
    }
  }

  const generateDraft = async () => {
    const prompt = draftPrompt.trim()
    if (!prompt || draftBusy) return

    setDraftBusy(true)
    setDraftError('')
    setDraftText('')
    try {
      const response = await apiClient.post('/v1/ai/draft', { kind: draftKind, prompt })
      setDraftText(response.data?.data?.draft ?? '')
    } catch (e: any) {
      setDraftError(e?.response?.data?.message || 'Не удалось создать черновик')
    } finally {
      setDraftBusy(false)
    }
  }

  if (isLoading) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', mt: 6 }}>
        <CircularProgress />
      </Box>
    )
  }

  if (status && !status.enabled) {
    return (
      <Box sx={{ maxWidth: 720, mx: 'auto', mt: 4 }}>
        <Alert severity="info">
          AI-ассистент не настроен на сервере. Задайте <code>AI_ENABLED=true</code> и <code>OLLAMA_URL</code>, затем
          перезапустите backend.
        </Alert>
      </Box>
    )
  }

  return (
    <Box sx={{ maxWidth: 820, mx: 'auto', mt: 2, display: 'flex', flexDirection: 'column', gap: 2 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        <AssistantIcon color="primary" />
        <Typography variant="h6" sx={{ fontWeight: 600 }}>
          Ассистент по базе и задачам
        </Typography>
        {status?.model && <Chip size="small" label={status.model} variant="outlined" />}
      </Box>

      {turns.length === 0 && (
        <Typography color="text.secondary" variant="body2">
          Задайте вопрос по базе знаний и задачам — ответ формируется локальной моделью на основе доступных вам
          документов.
        </Typography>
      )}

      <Stack spacing={2}>
        {turns.map((turn, index) => (
          <Paper key={index} variant="outlined" sx={{ p: 2 }}>
            <Typography sx={{ fontWeight: 600, mb: 1 }}>{turn.question}</Typography>
            {turn.error && <Alert severity="error">{turn.error}</Alert>}
            {turn.answer && (
              <Typography sx={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{turn.answer}</Typography>
            )}
            {turn.sources && turn.sources.length > 0 && (
              <>
                <Divider sx={{ my: 1.5 }} />
                <Typography variant="caption" color="text.secondary">
                  Источники
                </Typography>
                <Stack spacing={0.5} sx={{ mt: 0.5 }}>
                  {turn.sources.map((source) => (
                    <Box key={`${source.type}-${source.id}`} sx={{ display: 'flex', gap: 1, alignItems: 'baseline' }}>
                      <Chip size="small" label={TYPE_LABELS[source.type] ?? source.type} sx={{ flexShrink: 0 }} />
                      <Typography
                        component={RouterLink}
                        to={source.url}
                        variant="body2"
                        sx={{
                          color: 'primary.main',
                          textDecoration: 'none',
                          '&:hover': { textDecoration: 'underline' },
                        }}
                      >
                        {source.title}
                      </Typography>
                    </Box>
                  ))}
                </Stack>
              </>
            )}
          </Paper>
        ))}
      </Stack>

      <Paper variant="outlined" sx={{ p: 2 }}>
        <Typography variant="subtitle1" sx={{ fontWeight: 600, mb: 1 }}>
          Черновик
        </Typography>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} sx={{ mb: 1 }}>
          <TextField
            select
            size="small"
            label="Тип"
            value={draftKind}
            onChange={(e) => setDraftKind(e.target.value as 'task' | 'mail')}
            sx={{ minWidth: 160 }}
          >
            <MenuItem value="task">Задача</MenuItem>
            <MenuItem value="mail">Письмо</MenuItem>
          </TextField>
          <TextField
            size="small"
            fullWidth
            label="Что нужно?"
            value={draftPrompt}
            onChange={(e) => setDraftPrompt(e.target.value)}
          />
          <Button
            variant="outlined"
            onClick={generateDraft}
            disabled={draftBusy || !draftPrompt.trim()}
            sx={{ textTransform: 'none', whiteSpace: 'nowrap' }}
          >
            {draftBusy ? 'Создание…' : 'Создать'}
          </Button>
        </Stack>
        {draftError && <Alert severity="error">{draftError}</Alert>}
        {draftText && <Typography sx={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{draftText}</Typography>}
      </Paper>

      <Paper variant="outlined" sx={{ p: 1.5, position: 'sticky', bottom: 8 }}>
        <Box sx={{ display: 'flex', gap: 1, alignItems: 'flex-end' }}>
          <TextField
            fullWidth
            multiline
            maxRows={4}
            size="small"
            placeholder="Спросите что-нибудь…"
            value={question}
            onChange={(e) => setQuestion(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault()
                void ask()
              }
            }}
          />
          <Button
            variant="contained"
            onClick={ask}
            disabled={busy || !question.trim()}
            startIcon={busy ? <CircularProgress size={16} color="inherit" /> : <SendIcon />}
            sx={{ textTransform: 'none', whiteSpace: 'nowrap' }}
          >
            Спросить
          </Button>
          {turns.length > 0 && (
            <IconButton onClick={() => setTurns([])} disabled={busy} aria-label="Очистить">
              <DeleteIcon />
            </IconButton>
          )}
        </Box>
      </Paper>
    </Box>
  )
}
