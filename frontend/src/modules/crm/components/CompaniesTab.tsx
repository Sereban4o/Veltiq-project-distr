import { useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  IconButton,
  List,
  ListItemButton,
  ListItemText,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import { Add as AddIcon, Delete as DeleteIcon, Edit as EditIcon, Search as SearchIcon } from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { crmApi } from '../api'
import type { CrmCompany, OneCCounterparty } from '../types'

const empty: Partial<CrmCompany> = {
  name: '',
  website: '',
  phone: '',
  email: '',
  address: '',
  industry: '',
  notes: '',
  full_name: '',
  inn: '',
  kpp: '',
}

/** Map a raw 1С counterparty onto the CRM company form fields. */
function fromOneC(c: OneCCounterparty): Partial<CrmCompany> {
  const phone = c.ContactInfo?.find((x) => /телефон|phone/i.test(x.Kind))
  const email = c.ContactInfo?.find((x) => /почт|электрон|mail/i.test(x.Kind))
  const address = c.ContactInfo?.find((x) => /адрес|address/i.test(x.Kind))

  return {
    ref_1c: c.Ref || null,
    code_1c: c.Code || null,
    name: c.Description || c.FullDescription || '',
    full_name: c.FullDescription || null,
    inn: c.INN || null,
    kpp: c.KPP || null,
    legal_physical_person: c.LegalPhysicalPerson || null,
    registration_country: c.RegistrationCountry || null,
    phone: phone?.PhoneNumber || phone?.Presentation || null,
    email: email?.Value || email?.Presentation || null,
    address: address?.Presentation || address?.Value || null,
    contact_info: c.ContactInfo ?? null,
    deleted_in_1c: !!c.DeletionMark,
  }
}

export default function CompaniesTab() {
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [dialog, setDialog] = useState<Partial<CrmCompany> | null>(null)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)

  const [oneCQuery, setOneCQuery] = useState('')
  const [oneCSearching, setOneCSearching] = useState(false)
  const [oneCResults, setOneCResults] = useState<OneCCounterparty[]>([])
  const [oneCError, setOneCError] = useState<string | null>(null)

  const { data = [], isLoading } = useQuery<CrmCompany[]>({
    queryKey: ['crm-companies', search],
    queryFn: () => crmApi.companies(search),
  })

  const reload = () => queryClient.invalidateQueries({ queryKey: ['crm-companies'] })

  const openDialog = (company: Partial<CrmCompany>) => {
    setDialog(company)
    setSaveError(null)
    setOneCQuery('')
    setOneCResults([])
    setOneCError(null)
  }

  const searchOneC = async () => {
    const q = oneCQuery.trim()
    if (!q) return
    setOneCSearching(true)
    setOneCError(null)
    setOneCResults([])
    try {
      const items = await crmApi.searchCounterpartiesOneC(q)
      if (!items.length) {
        setOneCError('Контрагент не найден в 1С')
        return
      }
      setOneCResults(items)
    } catch (e: any) {
      setOneCError(e?.response?.data?.message || 'Сервис 1С недоступен')
    } finally {
      setOneCSearching(false)
    }
  }

  const applyOneC = (c: OneCCounterparty) => {
    setDialog((prev) => ({ ...prev, ...fromOneC(c) }))
    setOneCResults([])
    setOneCQuery('')
    setOneCError(null)
  }

  const save = async () => {
    if (!dialog?.name?.trim()) return
    setSaving(true)
    setSaveError(null)
    try {
      if (dialog.id) await crmApi.updateCompany(dialog.id, dialog)
      else await crmApi.createCompany(dialog)
      setDialog(null)
      reload()
    } catch (e: any) {
      setSaveError(e?.response?.data?.message || 'Не удалось сохранить компанию')
    } finally {
      setSaving(false)
    }
  }

  const remove = async (company: CrmCompany) => {
    if (!window.confirm(`Удалить компанию «${company.name}»?`)) return
    await crmApi.deleteCompany(company.id)
    reload()
  }

  const set = (field: keyof CrmCompany, value: string) => setDialog((prev) => ({ ...prev, [field]: value }))

  return (
    <Stack spacing={2}>
      <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
        <TextField
          size="small"
          placeholder="Поиск компаний..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          InputProps={{ startAdornment: <SearchIcon fontSize="small" sx={{ mr: 1, color: 'text.secondary' }} /> }}
          sx={{ maxWidth: 320 }}
        />
        <Box sx={{ flex: 1 }} />
        <Button
          variant="contained"
          startIcon={<AddIcon />}
          onClick={() => openDialog({ ...empty })}
          sx={{ textTransform: 'none' }}
        >
          Компания
        </Button>
      </Box>

      {isLoading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 5 }}>
          <CircularProgress />
        </Box>
      ) : (
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Название</TableCell>
                <TableCell>ИНН / КПП</TableCell>
                <TableCell>Сфера</TableCell>
                <TableCell>Телефон</TableCell>
                <TableCell>Email</TableCell>
                <TableCell align="right">Контакты</TableCell>
                <TableCell align="right">Сделки</TableCell>
                <TableCell align="right" />
              </TableRow>
            </TableHead>
            <TableBody>
              {data.length === 0 && (
                <TableRow>
                  <TableCell colSpan={8} align="center" sx={{ color: 'text.secondary', py: 3 }}>
                    Компаний пока нет
                  </TableCell>
                </TableRow>
              )}
              {data.map((company) => (
                <TableRow key={company.id} hover>
                  <TableCell>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, flexWrap: 'wrap' }}>
                      <Typography sx={{ fontSize: '0.88rem', fontWeight: 600 }}>{company.name}</Typography>
                      {company.ref_1c && <Chip label="1С" size="small" color="primary" variant="outlined" />}
                      {company.deleted_in_1c && (
                        <Chip label="удалён в 1С" size="small" color="error" variant="outlined" />
                      )}
                    </Box>
                  </TableCell>
                  <TableCell sx={{ fontSize: '0.83rem', color: 'text.secondary' }}>
                    {company.inn || '—'}
                    {company.kpp ? ` / ${company.kpp}` : ''}
                  </TableCell>
                  <TableCell sx={{ fontSize: '0.83rem', color: 'text.secondary' }}>{company.industry || '—'}</TableCell>
                  <TableCell sx={{ fontSize: '0.83rem', color: 'text.secondary' }}>{company.phone || '—'}</TableCell>
                  <TableCell sx={{ fontSize: '0.83rem', color: 'text.secondary' }}>{company.email || '—'}</TableCell>
                  <TableCell align="right">{company.contacts_count ?? 0}</TableCell>
                  <TableCell align="right">{company.deals_count ?? 0}</TableCell>
                  <TableCell align="right">
                    <Tooltip title="Редактировать">
                      <IconButton size="small" onClick={() => openDialog({ ...company })}>
                        <EditIcon fontSize="small" />
                      </IconButton>
                    </Tooltip>
                    <Tooltip title="Удалить">
                      <IconButton size="small" onClick={() => remove(company)}>
                        <DeleteIcon fontSize="small" />
                      </IconButton>
                    </Tooltip>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      )}

      <Dialog open={!!dialog} onClose={() => setDialog(null)} fullWidth maxWidth="sm">
        <DialogTitle>{dialog?.id ? 'Компания' : 'Новая компания'}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <Box>
              <Typography variant="subtitle2" sx={{ mb: 1 }}>
                Поиск в 1С
              </Typography>
              <Box sx={{ display: 'flex', gap: 1 }}>
                <TextField
                  size="small"
                  fullWidth
                  placeholder="ИНН или наименование"
                  value={oneCQuery}
                  onChange={(e) => setOneCQuery(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                      e.preventDefault()
                      searchOneC()
                    }
                  }}
                />
                <Button variant="outlined" onClick={searchOneC} disabled={oneCSearching} sx={{ textTransform: 'none' }}>
                  {oneCSearching ? 'Поиск...' : 'Найти'}
                </Button>
              </Box>

              {oneCError && (
                <Alert severity="info" sx={{ mt: 1 }}>
                  {oneCError}
                </Alert>
              )}

              {oneCResults.length > 0 && (
                <List
                  dense
                  sx={{ border: 1, borderColor: 'divider', borderRadius: 1, mt: 1, maxHeight: 200, overflow: 'auto' }}
                >
                  {oneCResults.map((c) => (
                    <ListItemButton key={c.Ref} onClick={() => applyOneC(c)}>
                      <ListItemText
                        primary={c.Description || c.FullDescription || c.Ref}
                        secondary={[c.INN && `ИНН ${c.INN}`, c.FullDescription].filter(Boolean).join(' · ')}
                      />
                    </ListItemButton>
                  ))}
                </List>
              )}
            </Box>

            {saveError && <Alert severity="error">{saveError}</Alert>}

            <TextField
              label="Название"
              value={dialog?.name ?? ''}
              onChange={(e) => set('name', e.target.value)}
              required
            />
            <TextField
              label="Полное наименование"
              value={dialog?.full_name ?? ''}
              onChange={(e) => set('full_name', e.target.value)}
            />
            <Box sx={{ display: 'flex', gap: 2 }}>
              <TextField label="ИНН" value={dialog?.inn ?? ''} onChange={(e) => set('inn', e.target.value)} fullWidth />
              <TextField label="КПП" value={dialog?.kpp ?? ''} onChange={(e) => set('kpp', e.target.value)} fullWidth />
            </Box>
            <TextField label="Сфера" value={dialog?.industry ?? ''} onChange={(e) => set('industry', e.target.value)} />
            <TextField label="Сайт" value={dialog?.website ?? ''} onChange={(e) => set('website', e.target.value)} />
            <TextField label="Телефон" value={dialog?.phone ?? ''} onChange={(e) => set('phone', e.target.value)} />
            <TextField label="Email" value={dialog?.email ?? ''} onChange={(e) => set('email', e.target.value)} />
            <TextField label="Адрес" value={dialog?.address ?? ''} onChange={(e) => set('address', e.target.value)} />
            <TextField
              label="Заметки"
              value={dialog?.notes ?? ''}
              onChange={(e) => set('notes', e.target.value)}
              multiline
              minRows={3}
            />
            {dialog?.ref_1c && (
              <Typography variant="caption" sx={{ color: 'text.secondary', wordBreak: 'break-all' }}>
                Ref 1С: {dialog.ref_1c}
              </Typography>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialog(null)} sx={{ textTransform: 'none' }}>
            Отмена
          </Button>
          <Button variant="contained" onClick={save} disabled={saving} sx={{ textTransform: 'none' }}>
            {saving ? 'Сохранение...' : 'Сохранить'}
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}
