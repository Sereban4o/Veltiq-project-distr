import { useMemo, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Checkbox,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControlLabel,
  MenuItem,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material'
import { crmApi } from '../api'
import type { CrmImportResult } from '../types'

export type CsvEntity = 'companies' | 'contacts' | 'deals'

const FIELD_LABELS: Record<CsvEntity, Record<string, string>> = {
  companies: {
    name: 'Название',
    full_name: 'Полное наименование',
    inn: 'ИНН',
    kpp: 'КПП',
    website: 'Сайт',
    phone: 'Телефон',
    email: 'Email',
    address: 'Адрес',
    industry: 'Сфера',
    notes: 'Заметки',
  },
  contacts: {
    first_name: 'Имя',
    last_name: 'Фамилия',
    email: 'Email',
    phone: 'Телефон',
    position: 'Должность',
    company: 'Компания',
    notes: 'Заметки',
  },
  deals: {
    title: 'Название',
    company: 'Компания',
    contact: 'Контакт (email)',
    stage: 'Этап',
    amount: 'Сумма',
    currency: 'Валюта',
    probability: 'Вероятность',
    expected_close_date: 'Дата закрытия',
    description: 'Описание',
  },
}

function parseHeaderLine(line: string): string[] {
  const delimiter = (line.match(/;/g)?.length ?? 0) >= (line.match(/,/g)?.length ?? 0) ? ';' : ','
  return line
    .split(delimiter)
    .map((h) => h.trim().replace(/^"|"$/g, ''))
    .filter((h) => h !== '')
}

interface Props {
  entity: CsvEntity
  open: boolean
  onClose: () => void
  onDone: () => void
}

export default function ImportDialog({ entity, open, onClose, onDone }: Props) {
  const [file, setFile] = useState<File | null>(null)
  const [headers, setHeaders] = useState<string[]>([])
  const [mapping, setMapping] = useState<Record<string, string>>({})
  const [updateExisting, setUpdateExisting] = useState(false)
  const [result, setResult] = useState<CrmImportResult | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const fields = useMemo(() => FIELD_LABELS[entity], [entity])

  const reset = () => {
    setFile(null)
    setHeaders([])
    setMapping({})
    setUpdateExisting(false)
    setResult(null)
    setError(null)
    setLoading(false)
  }

  const handleClose = () => {
    reset()
    onClose()
  }

  const handleFile = async (selected: File | null) => {
    setResult(null)
    setError(null)
    setFile(selected)
    if (!selected) {
      setHeaders([])
      setMapping({})
      return
    }

    const text = await selected.slice(0, 4096).text()
    const firstLine = text.replace(/^\uFEFF/, '').split(/\r?\n/)[0] ?? ''
    const parsed = parseHeaderLine(firstLine)
    setHeaders(parsed)

    const auto: Record<string, string> = {}
    Object.entries(fields).forEach(([field, label]) => {
      const found = parsed.find((h) => h.toLowerCase() === label.toLowerCase())
      if (found) auto[field] = found
    })
    setMapping(auto)
  }

  const buildFormData = (dryRun: boolean): FormData => {
    const fd = new FormData()
    if (file) fd.append('file', file)
    fd.append('mapping', JSON.stringify(mapping))
    fd.append('dry_run', dryRun ? '1' : '0')
    fd.append('update_existing', updateExisting ? '1' : '0')
    return fd
  }

  const run = async (dryRun: boolean) => {
    if (!file) return
    setLoading(true)
    setError(null)
    try {
      const data = await crmApi.importCsv(entity, buildFormData(dryRun))
      setResult(data)
      if (!dryRun && data.errors.length === 0) {
        onDone()
      }
    } catch (e: any) {
      const payload = e?.response?.data?.data as CrmImportResult | undefined
      if (payload) {
        setResult(payload)
      } else {
        setError(e?.response?.data?.message || 'Не удалось импортировать файл')
      }
    } finally {
      setLoading(false)
    }
  }

  return (
    <Dialog open={open} onClose={handleClose} fullWidth maxWidth="md">
      <DialogTitle>Импорт из CSV</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <Button variant="outlined" component="label" sx={{ alignSelf: 'flex-start', textTransform: 'none' }}>
            {file ? file.name : 'Выбрать CSV-файл'}
            <input
              type="file"
              accept=".csv,text/csv"
              hidden
              onChange={(e) => handleFile(e.target.files?.[0] ?? null)}
            />
          </Button>

          {error && <Alert severity="error">{error}</Alert>}

          {headers.length > 0 && (
            <Box>
              <Typography sx={{ fontSize: '0.85rem', fontWeight: 600, mb: 1 }}>Сопоставление колонок</Typography>
              <Stack spacing={1}>
                {Object.entries(fields).map(([field, label]) => (
                  <Box key={field} sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
                    <Typography sx={{ width: 200, fontSize: '0.85rem' }}>{label}</Typography>
                    <Select
                      size="small"
                      displayEmpty
                      value={mapping[field] ?? ''}
                      onChange={(e) => setMapping((prev) => ({ ...prev, [field]: e.target.value }))}
                      sx={{ flex: 1, fontSize: '0.85rem' }}
                    >
                      <MenuItem value="">
                        <em>Не импортировать</em>
                      </MenuItem>
                      {headers.map((h) => (
                        <MenuItem key={h} value={h}>
                          {h}
                        </MenuItem>
                      ))}
                    </Select>
                  </Box>
                ))}
              </Stack>
              <FormControlLabel
                sx={{ mt: 1 }}
                control={<Checkbox checked={updateExisting} onChange={(e) => setUpdateExisting(e.target.checked)} />}
                label="Обновлять существующие записи (по email/ИНН)"
              />
            </Box>
          )}

          {result && (
            <Box>
              <Alert severity={result.errors.length ? 'error' : 'success'} sx={{ mb: 1 }}>
                Всего строк: {result.total}. Валидных: {result.valid}. Создано: {result.created}. Обновлено:{' '}
                {result.updated}. Пропущено: {result.skipped}.
                {result.errors.length > 0 && ' Импорт отменён — исправьте ошибки.'}
              </Alert>

              {result.errors.length > 0 && (
                <Box sx={{ maxHeight: 200, overflow: 'auto', border: 1, borderColor: 'divider', borderRadius: 1 }}>
                  <Table size="small">
                    <TableHead>
                      <TableRow>
                        <TableCell>Строка</TableCell>
                        <TableCell>Ошибка</TableCell>
                      </TableRow>
                    </TableHead>
                    <TableBody>
                      {result.errors.map((err, i) => (
                        <TableRow key={i}>
                          <TableCell>{err.row}</TableCell>
                          <TableCell>{err.message}</TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </Box>
              )}

              {result.duplicates.length > 0 && (
                <Typography sx={{ fontSize: '0.8rem', color: 'text.secondary', mt: 1 }}>
                  Дубликатов: {result.duplicates.length}
                </Typography>
              )}
            </Box>
          )}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={handleClose} sx={{ textTransform: 'none' }}>
          Закрыть
        </Button>
        <Button variant="outlined" onClick={() => run(true)} disabled={!file || loading} sx={{ textTransform: 'none' }}>
          Проверить
        </Button>
        <Button
          variant="contained"
          onClick={() => run(false)}
          disabled={!file || loading || (!!result && result.errors.length > 0)}
          sx={{ textTransform: 'none' }}
        >
          {loading ? 'Импорт...' : 'Импортировать'}
        </Button>
      </DialogActions>
    </Dialog>
  )
}
