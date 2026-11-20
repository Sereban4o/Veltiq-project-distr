import { useEffect, useState } from 'react'
import { Alert, Box, Button, Container, Paper, Stack, TextField, Typography } from '@mui/material'
import { useParams } from 'react-router-dom'
import { knowledgebaseApi, type PublicPage } from '../api/knowledgebaseApi'

/**
 * Anonymous read-only view of a page shared by a public link.
 * The body is sanitised on the server before it reaches this component.
 */
export default function PublicPageView() {
  const { token = '' } = useParams()
  const [page, setPage] = useState<PublicPage | null>(null)
  const [password, setPassword] = useState('')
  const [needPassword, setNeedPassword] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = async (withPassword?: string) => {
    setLoading(true)
    setError('')
    try {
      setPage(await knowledgebaseApi.publicPage(token, withPassword))
      setNeedPassword(false)
    } catch (e: any) {
      if (e?.response?.status === 401) {
        setNeedPassword(true)
      } else {
        setError('Ссылка не найдена или истекла')
      }
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (token) void load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  return (
    <Box sx={{ minHeight: '100vh', bgcolor: 'grey.100', py: 6 }}>
      <Container maxWidth="md">
        <Paper sx={{ p: { xs: 3, md: 5 } }}>
          {loading && <Typography color="text.secondary">Загрузка…</Typography>}

          {!loading && error && <Alert severity="error">{error}</Alert>}

          {!loading && needPassword && (
            <Stack spacing={2} sx={{ maxWidth: 360 }}>
              <Typography variant="h6">Страница защищена паролем</Typography>
              <TextField
                type="password"
                label="Пароль"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') void load(password)
                }}
              />
              <Button variant="contained" onClick={() => void load(password)}>
                Открыть
              </Button>
            </Stack>
          )}

          {!loading && page && (
            <>
              <Typography variant="h4" sx={{ fontWeight: 700, mb: 1 }}>
                {page.title}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {[page.space, page.updated_at ? `обновлено ${new Date(page.updated_at).toLocaleString()}` : null]
                  .filter(Boolean)
                  .join(' · ')}
              </Typography>
              <Box
                sx={{
                  mt: 3,
                  '& h1': { fontSize: '1.8rem', fontWeight: 700, margin: '1rem 0' },
                  '& h2': { fontSize: '1.4rem', fontWeight: 600, margin: '0.8rem 0' },
                  '& h3': { fontSize: '1.15rem', fontWeight: 600, margin: '0.6rem 0' },
                  '& p': { margin: '0.5rem 0', lineHeight: 1.8 },
                  '& ul, & ol': { paddingLeft: 3, margin: '0.5rem 0' },
                  '& blockquote': {
                    borderLeft: '4px solid #dadce0',
                    paddingLeft: 2,
                    margin: '0.5rem 0',
                    color: 'text.secondary',
                  },
                  '& pre': { bgcolor: 'grey.100', p: 2, borderRadius: 1, overflowX: 'auto' },
                  '& img': { maxWidth: '100%' },
                }}
                dangerouslySetInnerHTML={{ __html: page.body || '<p>Пустая страница</p>' }}
              />
            </>
          )}
        </Paper>
      </Container>
    </Box>
  )
}
