import { useEffect, useRef, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  MenuItem,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import apiClient from '@/modules/shared/api/apiClient'
import RichTextEditor from '@/modules/shared/components/RichTextEditor'
import RecipientField, { addressValuesFrom, addressValuesToEmails } from './RecipientField'
import type { AddressValue, ComposeInitial, MailAccountSummary, MailMessage } from '../types'
import { htmlToText, textToHtml } from '../utils/html'

/** Seconds the user can still cancel a send (undo). */
const UNDO_SECONDS = 5

interface ComposeDialogProps {
  open: boolean
  draft?: MailMessage | null
  initial?: ComposeInitial | null
  /** Mailboxes the user can send from (own + shared with send permission). */
  accounts?: MailAccountSummary[]
  /** Mailbox pre-selected when composing (e.g. from the active shared inbox). */
  defaultAccountId?: number | null
  onClose: () => void
  onSent: () => void
}

function titleFor(draft: MailMessage | null | undefined, initial: ComposeInitial | null | undefined): string {
  if (draft) return 'Черновик'
  switch (initial?.mode) {
    case 'reply':
      return 'Ответ'
    case 'reply_all':
      return 'Ответить всем'
    case 'forward':
      return 'Переслать'
    default:
      return 'Новое письмо'
  }
}

export default function ComposeDialog({
  open,
  draft,
  initial,
  accounts = [],
  defaultAccountId = null,
  onClose,
  onSent,
}: ComposeDialogProps) {
  const sendableAccounts = accounts.filter((account) => account.can_send)
  const [to, setTo] = useState<AddressValue[]>([])
  const [cc, setCc] = useState<AddressValue[]>([])
  const [bcc, setBcc] = useState<AddressValue[]>([])
  const [subject, setSubject] = useState('')
  const [bodyHtml, setBodyHtml] = useState('')
  const [inReplyTo, setInReplyTo] = useState<string | null>(null)
  const [accountId, setAccountId] = useState<number | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [pendingSend, setPendingSend] = useState<number | null>(null)
  const [scheduleOpen, setScheduleOpen] = useState(false)
  const [scheduledAtInput, setScheduledAtInput] = useState('')
  const sendTimer = useRef<number | null>(null)
  const countdownTimer = useRef<number | null>(null)

  useEffect(() => {
    return () => {
      if (sendTimer.current !== null) window.clearTimeout(sendTimer.current)
      if (countdownTimer.current !== null) window.clearInterval(countdownTimer.current)
    }
  }, [])

  const clearSendTimers = () => {
    if (sendTimer.current !== null) window.clearTimeout(sendTimer.current)
    if (countdownTimer.current !== null) window.clearInterval(countdownTimer.current)
    sendTimer.current = null
    countdownTimer.current = null
  }

  const cancelPendingSend = () => {
    clearSendTimers()
    setPendingSend(null)
    setBusy(false)
  }

  useEffect(() => {
    if (!open) return
    setError('')
    setScheduleOpen(false)
    setScheduledAtInput('')
    const sendable = accounts.filter((account) => account.can_send)
    const preferred =
      (defaultAccountId != null ? sendable.find((a) => a.id === defaultAccountId) : undefined) ??
      sendable.find((a) => a.is_owner) ??
      sendable[0]
    setAccountId(preferred?.id ?? null)
    if (initial) {
      setTo(addressValuesFrom(initial.to))
      setCc(addressValuesFrom(initial.cc))
      setBcc(addressValuesFrom(initial.bcc))
      setSubject(initial.subject || '')
      setBodyHtml(initial.bodyHtml || textToHtml(initial.body || ''))
      setInReplyTo(initial.in_reply_to ?? null)
      return
    }
    setTo(addressValuesFrom(draft?.to_recipients))
    setCc(addressValuesFrom(draft?.cc_recipients))
    setBcc(addressValuesFrom(draft?.bcc_recipients))
    setSubject(draft?.subject || '')
    setBodyHtml(draft?.body_html || textToHtml(draft?.body_text || ''))
    setInReplyTo(null)
  }, [open, draft, initial, accounts, defaultAccountId])

  const payload = () => {
    const bodyText = htmlToText(bodyHtml)
    return {
      to: addressValuesToEmails(to),
      cc: addressValuesToEmails(cc),
      bcc: addressValuesToEmails(bcc),
      subject,
      body_text: bodyText,
      ...(bodyHtml.trim() ? { body_html: bodyHtml } : {}),
      ...(draft?.id ? { draft_id: draft.id } : {}),
      ...(inReplyTo ? { in_reply_to: inReplyTo } : {}),
      ...(accountId != null ? { account_id: accountId } : {}),
    }
  }

  const sendNow = async () => {
    clearSendTimers()
    setPendingSend(null)
    try {
      await apiClient.post('/v1/mail/messages', payload())
      onSent()
      onClose()
    } catch (e: any) {
      setBusy(false)
      setError(
        e?.response?.data?.message ||
          e?.response?.data?.errors?.to?.[0] ||
          e?.response?.data?.errors?.smtp?.[0] ||
          'Не удалось отправить письмо',
      )
    }
  }

  const handleSend = () => {
    setError('')
    if (!payload().to.length) {
      setError('Укажите хотя бы одного получателя')
      return
    }
    // Start a short countdown so the user can cancel (undo) the send.
    setBusy(true)
    setPendingSend(UNDO_SECONDS)
    countdownTimer.current = window.setInterval(
      () => setPendingSend((value) => (value !== null && value > 0 ? value - 1 : value)),
      1000,
    )
    sendTimer.current = window.setTimeout(() => void sendNow(), UNDO_SECONDS * 1000)
  }

  const requestClose = () => {
    cancelPendingSend()
    onClose()
  }

  const handleSchedule = async () => {
    setError('')
    if (!payload().to.length) {
      setError('Укажите хотя бы одного получателя')
      return
    }
    const when = new Date(scheduledAtInput)
    if (!scheduledAtInput || Number.isNaN(when.getTime())) {
      setError('Укажите время отправки')
      return
    }
    if (when.getTime() <= Date.now()) {
      setError('Время отправки должно быть в будущем')
      return
    }
    setBusy(true)
    try {
      await apiClient.post('/v1/mail/messages', { ...payload(), scheduled_at: when.toISOString() })
      onSent()
      onClose()
    } catch (e: any) {
      setBusy(false)
      setError(
        e?.response?.data?.message ||
          e?.response?.data?.errors?.to?.[0] ||
          e?.response?.data?.errors?.smtp?.[0] ||
          'Не удалось запланировать отправку',
      )
    }
  }

  const handleSaveDraft = async () => {
    setError('')
    setBusy(true)
    const bodyText = htmlToText(bodyHtml)
    const htmlPart = bodyHtml.trim() ? { body_html: bodyHtml } : {}
    try {
      if (draft?.id) {
        await apiClient.patch(`/v1/mail/messages/${draft.id}`, {
          to: addressValuesToEmails(to),
          subject,
          body_text: bodyText,
          ...htmlPart,
        })
      } else {
        await apiClient.post('/v1/mail/drafts', {
          to: addressValuesToEmails(to),
          subject,
          body_text: bodyText,
          ...htmlPart,
        })
      }
      onSent()
      onClose()
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Не удалось сохранить черновик')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Dialog open={open} onClose={requestClose} fullWidth maxWidth="md">
      <DialogTitle>{titleFor(draft, initial)}</DialogTitle>
      <DialogContent>
        {error && (
          <Alert severity="error" sx={{ mb: 2 }}>
            {error}
          </Alert>
        )}
        {scheduleOpen && (
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mt: 2 }}>
            <TextField
              type="datetime-local"
              label="Отправить в"
              size="small"
              value={scheduledAtInput}
              onChange={(e) => setScheduledAtInput(e.target.value)}
              InputLabelProps={{ shrink: true }}
            />
            <Button variant="outlined" onClick={handleSchedule} disabled={busy}>
              Запланировать
            </Button>
          </Stack>
        )}
        <Stack spacing={2} sx={{ mt: 1 }}>
          {sendableAccounts.length > 1 && (
            <TextField
              select
              size="small"
              label="От"
              value={accountId ?? ''}
              onChange={(e) => setAccountId(Number(e.target.value))}
              sx={{ maxWidth: 360 }}
            >
              {sendableAccounts.map((account) => (
                <MenuItem key={account.id} value={account.id}>
                  {account.display_name ? `${account.display_name} <${account.email}>` : account.email}
                  {account.is_owner ? '' : ' (общий)'}
                </MenuItem>
              ))}
            </TextField>
          )}
          <RecipientField label="Кому" value={to} onChange={setTo} />
          <RecipientField label="Копия" value={cc} onChange={setCc} />
          <RecipientField label="Скрытая копия" value={bcc} onChange={setBcc} />
          <TextField label="Тема" value={subject} onChange={(e) => setSubject(e.target.value)} />
          <Box>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.5 }}>
              Сообщение
            </Typography>
            <RichTextEditor content={bodyHtml} onChange={setBodyHtml} placeholder="Текст письма" minHeight={220} />
          </Box>
        </Stack>
        {pendingSend !== null && (
          <Alert
            severity="info"
            sx={{ mt: 2 }}
            action={
              <Button color="inherit" size="small" onClick={cancelPendingSend}>
                Отменить
              </Button>
            }
          >
            Отправка через {pendingSend} с…
          </Alert>
        )}
      </DialogContent>
      <DialogActions>
        <Box sx={{ flex: 1 }} />
        <Button onClick={() => setScheduleOpen((value) => !value)} disabled={busy}>
          Позже
        </Button>
        <Button onClick={handleSaveDraft} disabled={busy}>
          Сохранить черновик
        </Button>
        <Button variant="contained" onClick={handleSend} disabled={busy}>
          {busy ? (pendingSend !== null ? `Отправка через ${pendingSend} с…` : 'Отправка...') : 'Отправить'}
        </Button>
      </DialogActions>
    </Dialog>
  )
}
