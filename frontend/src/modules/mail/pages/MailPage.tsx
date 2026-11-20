import { useEffect, useMemo, useState, type ReactElement } from 'react'
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Divider,
  IconButton,
  List,
  ListItemButton,
  ListItemText,
  Menu,
  MenuItem,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import {
  Create as ComposeIcon,
  CreateNewFolder as NewFolderIcon,
  Contacts as ContactsIcon,
  Delete as DeleteIcon,
  DeleteSweep as ClearTrashIcon,
  Drafts as DraftsIcon,
  Download as DownloadIcon,
  DriveFileMove as MoveIcon,
  FilterList as RulesIcon,
  Forward as ForwardIcon,
  HowToReg as AssignIcon,
  TaskAlt as DoneIcon,
  Schedule as SnoozeIcon,
  Inbox as InboxIcon,
  MailOutline as MailIcon,
  PersonAdd as PersonAddIcon,
  Refresh as RefreshIcon,
  Reply as ReplyIcon,
  ReplyAll as ReplyAllIcon,
  Send as SentIcon,
} from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '@/modules/auth/store/authStore'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapData, unwrapList } from '@/modules/shared/utils/apiResponse'
import type {
  ComposeInitial,
  EmptyTrashResult,
  MailAccountSummary,
  MailAttachment,
  MailContactsImportResult,
  MailFolder,
  MailFolderRecord,
  MailMessage,
  MailSettings,
} from '../types'
import ComposeDialog from '../components/ComposeDialog'
import MailFolderDialog from '../components/MailFolderDialog'
import MailRulesDialog from '../components/MailRulesDialog'
import MailContactsDialog from '../components/MailContactsDialog'
import { buildReply } from '../utils/reply'

const FOLDERS: { value: MailFolder; label: string; icon: ReactElement }[] = [
  { value: 'inbox', label: 'Входящие', icon: <InboxIcon /> },
  { value: 'sent', label: 'Исходящие', icon: <SentIcon /> },
  { value: 'drafts', label: 'Черновики', icon: <DraftsIcon /> },
  { value: 'trash', label: 'Корзина', icon: <DeleteIcon /> },
]

type FolderSelection = { type: 'system'; folder: MailFolder } | { type: 'custom'; id: number }

function formatDate(value: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function recipientLine(message: MailMessage): string {
  return message.from_name || message.from_email || ''
}

export default function MailPage() {
  const queryClient = useQueryClient()
  const [selection, setSelection] = useState<FolderSelection>({ type: 'system', folder: 'inbox' })
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [composeOpen, setComposeOpen] = useState(false)
  const [composeDraft, setComposeDraft] = useState<MailMessage | null>(null)
  const [composeInitial, setComposeInitial] = useState<ComposeInitial | null>(null)
  const [folderDialog, setFolderDialog] = useState<{ open: boolean; folder: MailFolderRecord | null }>({
    open: false,
    folder: null,
  })
  const [rulesOpen, setRulesOpen] = useState(false)
  const [contactsOpen, setContactsOpen] = useState(false)
  const [moveAnchor, setMoveAnchor] = useState<null | HTMLElement>(null)
  const [notice, setNotice] = useState<{ type: 'success' | 'error' | 'warning'; text: string } | null>(null)
  const [syncing, setSyncing] = useState(false)
  const [clearingTrash, setClearingTrash] = useState(false)

  const { data: customFolders = [] } = useQuery<MailFolderRecord[]>({
    queryKey: ['mail-folders'],
    queryFn: async () => unwrapList<MailFolderRecord>(await apiClient.get('/v1/mail/folders')),
  })

  const { data: mailSettings } = useQuery<MailSettings | null>({
    queryKey: ['mail-settings'],
    queryFn: async () => unwrapData<MailSettings>(await apiClient.get('/v1/mail/settings')),
  })

  const { data: accounts = [] } = useQuery<MailAccountSummary[]>({
    queryKey: ['mail-accounts'],
    queryFn: async () => unwrapList<MailAccountSummary>(await apiClient.get('/v1/mail/accounts')),
  })

  const [activeAccountId, setActiveAccountId] = useState<number | null>(null)

  useEffect(() => {
    if (activeAccountId !== null || accounts.length === 0) return
    const preferred = accounts.find((a) => a.is_owner) ?? accounts[0]
    setActiveAccountId(preferred.id)
  }, [accounts, activeAccountId])

  // Only show the mailbox switcher (and filter) when more than one inbox exists.
  const multiMailbox = accounts.length > 1
  const activeAccount = accounts.find((a) => a.id === activeAccountId) ?? null

  const ownEmail = mailSettings?.email ?? null
  const { user: authUser } = useAuth()

  const { data: messages = [], isLoading } = useQuery<MailMessage[]>({
    queryKey: ['mail-messages', selection, multiMailbox ? activeAccountId : null],
    queryFn: async () => {
      const params: Record<string, string | number> =
        selection.type === 'system'
          ? { folder: selection.folder, per_page: 50 }
          : { folder_id: selection.id, per_page: 50 }
      if (multiMailbox && activeAccountId !== null) params.account_id = activeAccountId
      return unwrapList<MailMessage>(await apiClient.get('/v1/mail/messages', { params }))
    },
    staleTime: 5_000,
  })

  const selected = useMemo(() => messages.find((m) => m.id === selectedId) ?? null, [messages, selectedId])

  const currentLabel =
    selection.type === 'system'
      ? FOLDERS.find((f) => f.value === selection.folder)?.label
      : customFolders.find((f) => f.id === selection.id)?.name

  const reload = () => {
    queryClient.invalidateQueries({ queryKey: ['mail-messages'] })
    queryClient.invalidateQueries({ queryKey: ['mail-folders'] })
  }

  const handleSync = async () => {
    setNotice(null)
    setSyncing(true)
    try {
      const res = await apiClient.post('/v1/mail/sync')
      setNotice({ type: 'success', text: `Загружено новых писем: ${res.data?.stored ?? 0}` })
      reload()
    } catch (e: any) {
      setNotice({ type: 'error', text: e?.response?.data?.message || 'Не удалось синхронизировать почту' })
    } finally {
      setSyncing(false)
    }
  }

  const handleSelect = async (message: MailMessage) => {
    setSelectedId(message.id)
    if (!message.is_read && message.folder === 'inbox') {
      try {
        await apiClient.get(`/v1/mail/messages/${message.id}`)
        reload()
      } catch {
        /* ignore */
      }
    }
  }

  const handleDelete = async (message: MailMessage) => {
    try {
      await apiClient.delete(`/v1/mail/messages/${message.id}`)
      if (selectedId === message.id) setSelectedId(null)
      reload()
    } catch {
      /* ignore */
    }
  }

  const handleEmptyTrash = async () => {
    if (!window.confirm('Удалить все письма из корзины? Письма будут удалены и на почтовом сервере.')) return
    setNotice(null)
    setClearingTrash(true)
    try {
      const res = await apiClient.delete<EmptyTrashResult>('/v1/mail/trash')
      setNotice({ type: res.data.failed > 0 ? 'warning' : 'success', text: res.data.message })
      setSelectedId(null)
      reload()
    } catch (e: any) {
      setNotice({ type: 'error', text: e?.response?.data?.message || 'Не удалось очистить корзину' })
    } finally {
      setClearingTrash(false)
    }
  }

  const handleAddToContacts = async () => {
    if (!selected) return
    try {
      const res = await apiClient.post<MailContactsImportResult>(`/v1/mail/messages/${selected.id}/contacts`)
      setNotice({
        type: 'success',
        text: `В адресную книгу: добавлено ${res.data.added}, обновлено ${res.data.updated}, уже было ${res.data.skipped}`,
      })
      queryClient.invalidateQueries({ queryKey: ['mail-contacts'] })
    } catch (e: any) {
      setNotice({ type: 'error', text: e?.response?.data?.message || 'Не удалось добавить в адресную книгу' })
    }
  }

  const openComposeNew = () => {
    setComposeDraft(null)
    setComposeInitial(null)
    setComposeOpen(true)
  }

  const patchSelected = async (payload: Record<string, unknown>) => {
    if (!selected) return
    await apiClient.patch(`/v1/mail/messages/${selected.id}`, payload)
    reload()
  }

  const openReply = (mode: 'reply' | 'reply_all' | 'forward') => {
    if (!selected) return
    setComposeDraft(null)
    setComposeInitial(buildReply(selected, mode, ownEmail))
    setComposeOpen(true)
  }

  const handleMove = async (target: { folder: MailFolder } | { folderId: number }) => {
    if (!selected) return
    setMoveAnchor(null)
    try {
      if ('folderId' in target) {
        await apiClient.patch(`/v1/mail/messages/${selected.id}`, { folder_id: target.folderId })
      } else {
        await apiClient.patch(`/v1/mail/messages/${selected.id}`, { folder: target.folder })
      }
      reload()
      if (selection.type === 'custom') setSelectedId(null)
    } catch {
      /* ignore */
    }
  }

  const downloadAttachment = async (attachment: MailAttachment) => {
    if (!selected) return
    const res = await apiClient.get(`/v1/mail/messages/${selected.id}/attachments/${attachment.id}`, {
      responseType: 'blob',
    })
    const url = URL.createObjectURL(res.data as Blob)
    const link = document.createElement('a')
    link.href = url
    link.download = attachment.filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  }

  return (
    <Box
      sx={{
        display: 'flex',
        height: 'calc(100vh - 120px)',
        border: '1px solid',
        borderColor: 'divider',
        borderRadius: 2,
        overflow: 'hidden',
        bgcolor: 'background.paper',
      }}
    >
      {/* Folders */}
      <Box
        sx={{
          width: 210,
          flexShrink: 0,
          borderRight: '1px solid',
          borderColor: 'divider',
          p: 1,
          display: 'flex',
          flexDirection: 'column',
        }}
      >
        <Button
          fullWidth
          variant="contained"
          startIcon={<ComposeIcon />}
          onClick={openComposeNew}
          sx={{ mb: 2, textTransform: 'none' }}
        >
          Написать
        </Button>

        {multiMailbox && (
          <TextField
            select
            size="small"
            label="Ящик"
            value={activeAccountId ?? ''}
            onChange={(e) => {
              setActiveAccountId(Number(e.target.value))
              setSelection({ type: 'system', folder: 'inbox' })
              setSelectedId(null)
            }}
            sx={{ mb: 2 }}
          >
            {accounts.map((account) => (
              <MenuItem key={account.id} value={account.id}>
                {account.email}
                {account.is_owner ? '' : ' (общий)'}
              </MenuItem>
            ))}
          </TextField>
        )}

        <List dense sx={{ flex: 1, overflow: 'auto' }}>
          {FOLDERS.map((f) => (
            <ListItemButton
              key={f.value}
              selected={selection.type === 'system' && selection.folder === f.value}
              onClick={() => {
                setSelection({ type: 'system', folder: f.value })
                setSelectedId(null)
              }}
              sx={{ borderRadius: 2, mb: 0.5 }}
            >
              <Box sx={{ mr: 1.5, display: 'flex', color: 'text.secondary' }}>{f.icon}</Box>
              <ListItemText primary={f.label} />
            </ListItemButton>
          ))}

          {customFolders.length > 0 && <Divider sx={{ my: 1 }} />}

          {customFolders.map((f) => (
            <ListItemButton
              key={`custom-${f.id}`}
              selected={selection.type === 'custom' && selection.id === f.id}
              onClick={() => {
                setSelection({ type: 'custom', id: f.id })
                setSelectedId(null)
              }}
              sx={{ borderRadius: 2, mb: 0.5 }}
            >
              <Box sx={{ mr: 1.5, display: 'flex', color: 'text.secondary' }}>
                <NewFolderIcon fontSize="small" />
              </Box>
              <ListItemText
                primary={f.name}
                secondary={typeof f.messages_count === 'number' ? String(f.messages_count) : undefined}
              />
            </ListItemButton>
          ))}
        </List>

        <Button
          fullWidth
          size="small"
          startIcon={<NewFolderIcon />}
          onClick={() => setFolderDialog({ open: true, folder: null })}
          sx={{ textTransform: 'none', justifyContent: 'flex-start', mt: 1 }}
        >
          Новая папка
        </Button>
        <Button
          fullWidth
          size="small"
          startIcon={<RulesIcon />}
          onClick={() => setRulesOpen(true)}
          sx={{ textTransform: 'none', justifyContent: 'flex-start' }}
        >
          Правила
        </Button>
        <Button
          fullWidth
          size="small"
          startIcon={<ContactsIcon />}
          onClick={() => setContactsOpen(true)}
          sx={{ textTransform: 'none', justifyContent: 'flex-start' }}
        >
          Адресная книга
        </Button>
      </Box>

      {/* Message list */}
      <Box
        sx={{
          width: 360,
          flexShrink: 0,
          borderRight: '1px solid',
          borderColor: 'divider',
          display: 'flex',
          flexDirection: 'column',
        }}
      >
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', px: 2, py: 1.5 }}>
          <Typography sx={{ fontWeight: 600 }}>{currentLabel ?? 'Папка'}</Typography>
          <Box sx={{ display: 'flex', alignItems: 'center' }}>
            {selection.type === 'system' && selection.folder === 'trash' && (
              <Tooltip title="Очистить корзину">
                <span>
                  <IconButton size="small" disabled={clearingTrash || messages.length === 0} onClick={handleEmptyTrash}>
                    {clearingTrash ? <CircularProgress size={18} /> : <ClearTrashIcon />}
                  </IconButton>
                </span>
              </Tooltip>
            )}
            <Tooltip title="Синхронизировать">
              <IconButton size="small" onClick={handleSync} disabled={syncing}>
                {syncing ? <CircularProgress size={18} /> : <RefreshIcon />}
              </IconButton>
            </Tooltip>
          </Box>
        </Box>
        <Divider />
        <Box sx={{ flex: 1, overflow: 'auto' }}>
          {isLoading && (
            <Box sx={{ display: 'flex', justifyContent: 'center', py: 4 }}>
              <CircularProgress size={24} />
            </Box>
          )}
          {!isLoading && messages.length === 0 && (
            <Typography sx={{ p: 3, textAlign: 'center', color: 'text.secondary', fontSize: '0.85rem' }}>
              Писем нет
            </Typography>
          )}
          {messages.map((message) => (
            <ListItemButton
              key={message.id}
              selected={selectedId === message.id}
              onClick={() => handleSelect(message)}
              alignItems="flex-start"
              sx={{ borderBottom: '1px solid', borderColor: 'divider' }}
            >
              <ListItemText
                primary={
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 1 }}>
                    <Typography
                      noWrap
                      sx={{ fontSize: '0.88rem', fontWeight: message.is_read ? 400 : 700, flex: 1, minWidth: 0 }}
                    >
                      {message.folder === 'sent' || message.folder === 'drafts'
                        ? message.to_recipients
                            ?.map((r) => r.email)
                            .filter(Boolean)
                            .join(', ') || '(без получателя)'
                        : recipientLine(message) || '(без отправителя)'}
                    </Typography>
                    <Typography sx={{ fontSize: '0.7rem', color: 'text.secondary', flexShrink: 0 }}>
                      {formatDate(message.sent_at || message.received_at || message.created_at)}
                    </Typography>
                  </Box>
                }
                secondary={
                  <Box component="span">
                    <Typography
                      component="span"
                      noWrap
                      sx={{ fontSize: '0.82rem', fontWeight: message.is_read ? 400 : 600, display: 'block' }}
                    >
                      {message.subject || '(без темы)'}
                      {message.has_attachments ? ' 📎' : ''}
                    </Typography>
                    <Typography
                      component="span"
                      noWrap
                      sx={{ fontSize: '0.78rem', color: 'text.secondary', display: 'block' }}
                    >
                      {message.preview || ''}
                    </Typography>
                  </Box>
                }
              />
            </ListItemButton>
          ))}
        </Box>
      </Box>

      {/* Reading pane */}
      <Box sx={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        {notice && (
          <Alert severity={notice.type} sx={{ borderRadius: 0 }} onClose={() => setNotice(null)}>
            {notice.text}
          </Alert>
        )}
        {!selected ? (
          <Box
            sx={{
              flex: 1,
              display: 'flex',
              flexDirection: 'column',
              alignItems: 'center',
              justifyContent: 'center',
              color: 'text.secondary',
              gap: 1,
            }}
          >
            <MailIcon sx={{ fontSize: 48, opacity: 0.5 }} />
            <Typography>Выберите письмо</Typography>
          </Box>
        ) : (
          <>
            <Box sx={{ px: 3, py: 2, borderBottom: '1px solid', borderColor: 'divider' }}>
              <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 1 }}>
                <Typography sx={{ fontSize: '1.05rem', fontWeight: 700, flex: 1 }}>
                  {selected.subject || '(без темы)'}
                </Typography>
                <Tooltip title="Ответить">
                  <IconButton size="small" onClick={() => openReply('reply')}>
                    <ReplyIcon fontSize="small" />
                  </IconButton>
                </Tooltip>
                <Tooltip title="Ответить всем">
                  <IconButton size="small" onClick={() => openReply('reply_all')}>
                    <ReplyAllIcon fontSize="small" />
                  </IconButton>
                </Tooltip>
                <Tooltip title="Переслать">
                  <IconButton size="small" onClick={() => openReply('forward')}>
                    <ForwardIcon fontSize="small" />
                  </IconButton>
                </Tooltip>
                <Tooltip title="В адресную книгу">
                  <IconButton size="small" onClick={handleAddToContacts}>
                    <PersonAddIcon fontSize="small" />
                  </IconButton>
                </Tooltip>
                <Tooltip title="Назначить мне">
                  <IconButton size="small" onClick={() => patchSelected({ assigned_to: authUser?.id ?? null })}>
                    <AssignIcon fontSize="small" />
                  </IconButton>
                </Tooltip>
                <Tooltip title={selected.is_done ? 'Снять отметку' : 'Готово'}>
                  <IconButton size="small" onClick={() => patchSelected({ is_done: !selected.is_done })}>
                    <DoneIcon fontSize="small" color={selected.is_done ? 'primary' : 'inherit'} />
                  </IconButton>
                </Tooltip>
                <Tooltip title="Отложить на день">
                  <IconButton
                    size="small"
                    onClick={() => patchSelected({ snoozed_until: new Date(Date.now() + 86400000).toISOString() })}
                  >
                    <SnoozeIcon fontSize="small" />
                  </IconButton>
                </Tooltip>
                <Tooltip title="Переместить">
                  <IconButton size="small" onClick={(e) => setMoveAnchor(e.currentTarget)}>
                    <MoveIcon fontSize="small" />
                  </IconButton>
                </Tooltip>
                <Tooltip title="Удалить">
                  <IconButton size="small" onClick={() => handleDelete(selected)}>
                    <DeleteIcon fontSize="small" />
                  </IconButton>
                </Tooltip>
              </Box>
              <Typography sx={{ fontSize: '0.85rem', color: 'text.secondary', mt: 1 }}>
                От: {selected.from_name || selected.from_email || '—'}
              </Typography>
              <Typography sx={{ fontSize: '0.85rem', color: 'text.secondary' }}>
                Кому:{' '}
                {selected.to_recipients
                  ?.map((r) => r.email)
                  .filter(Boolean)
                  .join(', ') || '—'}
              </Typography>
              <Typography sx={{ fontSize: '0.78rem', color: 'text.secondary', mt: 0.5 }}>
                {formatDate(selected.sent_at || selected.received_at || selected.created_at)}
              </Typography>
            </Box>

            <Box sx={{ flex: 1, overflow: 'auto', p: 2 }}>
              {selected.attachments && selected.attachments.length > 0 && (
                <Box sx={{ mb: 2, display: 'flex', flexWrap: 'wrap', gap: 1 }}>
                  {selected.attachments.map((att) => (
                    <Button
                      key={att.id}
                      size="small"
                      variant="outlined"
                      startIcon={<DownloadIcon />}
                      onClick={() => downloadAttachment(att)}
                      sx={{ textTransform: 'none' }}
                    >
                      {att.filename}
                    </Button>
                  ))}
                </Box>
              )}

              {selected.body_html ? (
                <Box
                  component="iframe"
                  title="Письмо"
                  sandbox=""
                  srcDoc={selected.body_html}
                  sx={{ width: '100%', height: '100%', minHeight: 320, border: 'none', bgcolor: '#fff' }}
                />
              ) : (
                <Typography sx={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', fontSize: '0.9rem' }}>
                  {selected.body_text || selected.preview || ''}
                </Typography>
              )}
            </Box>
          </>
        )}
      </Box>

      <Menu anchorEl={moveAnchor} open={Boolean(moveAnchor)} onClose={() => setMoveAnchor(null)}>
        {customFolders.map((f) => (
          <MenuItem key={`move-custom-${f.id}`} onClick={() => handleMove({ folderId: f.id })}>
            {f.name}
          </MenuItem>
        ))}
        {customFolders.length > 0 && <Divider />}
        {FOLDERS.map((f) => (
          <MenuItem key={`move-${f.value}`} onClick={() => handleMove({ folder: f.value })}>
            {f.label}
          </MenuItem>
        ))}
      </Menu>

      <ComposeDialog
        open={composeOpen}
        draft={composeDraft}
        initial={composeInitial}
        accounts={accounts}
        defaultAccountId={activeAccount?.can_send ? activeAccount.id : null}
        onClose={() => {
          setComposeOpen(false)
          setComposeInitial(null)
        }}
        onSent={reload}
      />

      <MailFolderDialog
        open={folderDialog.open}
        folder={folderDialog.folder}
        onClose={() => setFolderDialog({ open: false, folder: null })}
        onSaved={() => {
          queryClient.invalidateQueries({ queryKey: ['mail-folders'] })
        }}
      />

      <MailRulesDialog open={rulesOpen} folders={customFolders} onClose={() => setRulesOpen(false)} />

      <MailContactsDialog open={contactsOpen} onClose={() => setContactsOpen(false)} />
    </Box>
  )
}
