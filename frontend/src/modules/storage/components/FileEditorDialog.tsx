import { useEffect, useMemo, useRef, useState } from 'react'
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import { Add as AddIcon, Close as CloseIcon, Download as DownloadIcon, Save as SaveIcon } from '@mui/icons-material'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapData } from '@/modules/shared/utils/apiResponse'
import RichTextEditor from '@/modules/shared/components/RichTextEditor'
import OnlyOfficeEditor from './OnlyOfficeEditor'
import type { StorageFile } from '../types'

interface FileEditorDialogProps {
  file: StorageFile | null
  onClose: () => void
  onSaved: (message: string) => void
  onError: (message: string) => void
  onDownload: (path: string, name: string) => void
}

function parseDelimited(content: string, delimiter: string): string[][] {
  const lines = content.split(/\r?\n/).filter((line) => line !== '')
  const rows = lines.map((line) => line.split(delimiter))
  return rows.length ? rows : [['']]
}

function serializeDelimited(rows: string[][], delimiter: string): string {
  return rows.map((row) => row.join(delimiter)).join('\n')
}

export default function FileEditorDialog({ file, onClose, onSaved, onError, onDownload }: FileEditorDialogProps) {
  const [text, setText] = useState('')
  const [html, setHtml] = useState('')
  const [rows, setRows] = useState<string[][]>([['']])
  const [objectUrl, setObjectUrl] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  const urlRef = useRef<string | null>(null)

  const delimiter = useMemo(() => (file?.extension === 'tsv' ? '\t' : ','), [file?.extension])
  const mode = file?.editor ?? 'binary'
  const isOffice = mode === 'office'

  useEffect(() => {
    if (!file) return
    setText(file.content ?? '')
    setHtml(
      file.content && /<[a-z][\s\S]*>/i.test(file.content)
        ? file.content
        : `<p>${(file.content ?? '').replace(/</g, '&lt;')}</p>`,
    )
    if (mode === 'grid') {
      setRows(parseDelimited(file.content ?? '', delimiter))
    }

    if (urlRef.current) {
      URL.revokeObjectURL(urlRef.current)
      urlRef.current = null
    }
    setObjectUrl(null)

    if (mode === 'image' || mode === 'pdf') {
      apiClient
        .get('/v1/storage/raw', { params: { path: file.path }, responseType: 'blob', timeout: 0 })
        .then((res) => {
          const url = URL.createObjectURL(res.data as Blob)
          urlRef.current = url
          setObjectUrl(url)
        })
        .catch(() => setObjectUrl(null))
    }
  }, [file, mode, delimiter])

  useEffect(
    () => () => {
      if (urlRef.current) URL.revokeObjectURL(urlRef.current)
    },
    [],
  )

  const save = async () => {
    if (!file) return
    let content = text
    if (mode === 'richtext') content = html
    if (mode === 'grid') content = serializeDelimited(rows, delimiter)

    setSaving(true)
    try {
      const updated = unwrapData<StorageFile>(await apiClient.put('/v1/storage/file', { path: file.path, content }))
      if (updated) {
        setText(updated.content ?? '')
      }
      onSaved('Файл сохранён')
    } catch (e: any) {
      onError(e?.response?.data?.message || 'Не удалось сохранить файл')
    } finally {
      setSaving(false)
    }
  }

  const canSave = !!file && ['text', 'richtext', 'grid'].includes(mode)

  return (
    <Dialog
      open={!!file}
      onClose={onClose}
      fullWidth
      maxWidth={isOffice ? false : 'lg'}
      PaperProps={
        isOffice
          ? { sx: { width: '100%', maxWidth: 'none', m: 1, height: '92vh', display: 'flex', flexDirection: 'column' } }
          : undefined
      }
    >
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        {file?.name}
        <Typography component="span" sx={{ fontSize: '0.8rem', color: 'text.secondary', ml: 1 }}>
          {file?.kind}
        </Typography>
        <Box sx={{ flex: 1 }} />
        <IconButton size="small" onClick={onClose}>
          <CloseIcon fontSize="small" />
        </IconButton>
      </DialogTitle>
      <Divider />
      <DialogContent
        sx={
          isOffice
            ? { bgcolor: 'background.default', p: 0, flex: 1, minHeight: 0, display: 'flex', overflow: 'hidden' }
            : { bgcolor: 'background.default' }
        }
      >
        {mode === 'richtext' && (
          <Box sx={{ bgcolor: '#fff' }}>
            <RichTextEditor content={html} onChange={setHtml} minHeight={360} />
          </Box>
        )}

        {mode === 'text' && (
          <TextField
            fullWidth
            multiline
            minRows={20}
            value={text}
            onChange={(e) => setText(e.target.value)}
            sx={{ bgcolor: '#fff', '& textarea': { fontFamily: 'monospace', fontSize: '0.85rem' } }}
          />
        )}

        {mode === 'grid' && (
          <Box sx={{ bgcolor: '#fff', overflow: 'auto', maxHeight: '60vh' }}>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell sx={{ width: 40 }} />
                  {rows[0]?.map((_, colIndex) => (
                    <TableCell key={colIndex}>
                      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                        <span>{String.fromCharCode(65 + (colIndex % 26))}</span>
                        <IconButton
                          size="small"
                          onClick={() => setRows((prev) => prev.map((row) => row.filter((_, i) => i !== colIndex)))}
                        >
                          <CloseIcon sx={{ fontSize: 14 }} />
                        </IconButton>
                      </Box>
                    </TableCell>
                  ))}
                </TableRow>
              </TableHead>
              <TableBody>
                {rows.map((row, rowIndex) => (
                  <TableRow key={rowIndex}>
                    <TableCell>
                      <IconButton size="small" onClick={() => setRows((prev) => prev.filter((_, i) => i !== rowIndex))}>
                        <CloseIcon sx={{ fontSize: 14 }} />
                      </IconButton>
                    </TableCell>
                    {row.map((cell, colIndex) => (
                      <TableCell key={colIndex} padding="none">
                        <TextField
                          value={cell}
                          onChange={(e) =>
                            setRows((prev) =>
                              prev.map((r, ri) =>
                                ri === rowIndex ? r.map((c, ci) => (ci === colIndex ? e.target.value : c)) : r,
                              ),
                            )
                          }
                          variant="standard"
                          sx={{ px: 1, minWidth: 100 }}
                        />
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            <Box sx={{ display: 'flex', gap: 1, p: 1 }}>
              <Button
                size="small"
                startIcon={<AddIcon />}
                onClick={() => setRows((prev) => [...prev, new Array(prev[0]?.length || 1).fill('')])}
              >
                Строка
              </Button>
              <Button
                size="small"
                startIcon={<AddIcon />}
                onClick={() => setRows((prev) => prev.map((row) => [...row, '']))}
              >
                Столбец
              </Button>
            </Box>
          </Box>
        )}

        {mode === 'pdf' && objectUrl && (
          <Box
            component="iframe"
            title="PDF"
            src={objectUrl}
            sx={{ width: '100%', height: '70vh', border: 'none', bgcolor: '#fff' }}
          />
        )}

        {mode === 'image' && objectUrl && (
          <Box sx={{ display: 'flex', justifyContent: 'center' }}>
            <Box component="img" src={objectUrl} alt={file?.name} sx={{ maxWidth: '100%', maxHeight: '70vh' }} />
          </Box>
        )}

        {mode === 'office' && file && (
          <OnlyOfficeEditor path={file.path} onSaved={() => onSaved('Документ сохранён')} />
        )}

        {mode === 'binary' && <Alert severity="info">Файл нельзя открыть как текст. Скачайте его для просмотра.</Alert>}
      </DialogContent>
      <DialogActions>
        {file && (
          <Button
            startIcon={<DownloadIcon />}
            onClick={() => onDownload(file.path, file.name)}
            sx={{ textTransform: 'none' }}
          >
            Скачать
          </Button>
        )}
        <Box sx={{ flex: 1 }} />
        <Button onClick={onClose} sx={{ textTransform: 'none' }}>
          Закрыть
        </Button>
        {canSave && (
          <Tooltip title="Сохранить в файл">
            <span>
              <Button
                variant="contained"
                startIcon={<SaveIcon />}
                onClick={save}
                disabled={saving}
                sx={{ textTransform: 'none' }}
              >
                {saving ? 'Сохранение...' : 'Сохранить'}
              </Button>
            </span>
          </Tooltip>
        )}
      </DialogActions>
    </Dialog>
  )
}
