import type { ComposeInitial, MailMessage } from '../types'
import { escapeHtml, textToHtml } from './html'

function stripTags(html: string): string {
  return html
    .replace(/<[^>]*>/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

function originalBody(message: MailMessage): string {
  if (message.body_text && message.body_text.trim()) return message.body_text
  if (message.body_html) return stripTags(message.body_html)
  return message.preview ?? ''
}

/** Original message as HTML: keeps its markup when available. */
function originalHtml(message: MailMessage): string {
  if (message.body_html && message.body_html.trim()) return message.body_html
  return textToHtml(originalBody(message))
}

function formatDate(value: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function stripPrefix(subject: string, prefixes: string[]): string {
  let value = subject.trim()
  const re = new RegExp('^(' + prefixes.join('|') + '):\\s*', 'i')
  while (re.test(value)) value = value.replace(re, '')
  return value
}

function emails(list?: { email?: string | null }[] | null): string[] {
  if (!list) return []
  return list.map((r) => (r.email || '').trim()).filter(Boolean)
}

function dedupe(list: string[], exclude: string | null): string[] {
  const own = (exclude || '').trim().toLowerCase()
  const seen = new Set<string>()
  const result: string[] = []

  for (const raw of list) {
    const item = raw.trim()
    const key = item.toLowerCase()
    if (!key || key === own || seen.has(key)) continue
    seen.add(key)
    result.push(item)
  }

  return result
}

/** Builds a reply / reply-all / forward prefill for the compose dialog. */
export function buildReply(
  original: MailMessage,
  mode: 'reply' | 'reply_all' | 'forward',
  ownEmail: string | null,
): ComposeInitial {
  const body = originalBody(original)
  const quoted = body
    .split(/\r?\n/)
    .map((line) => `> ${line}`)
    .join('\n')
  const header =
    `\n\n---\n${formatDate(original.sent_at || original.received_at || original.created_at)} ` +
    `${original.from_name || original.from_email || ''} написал(а):\n`
  const quote = header + quoted + '\n'

  // The visual editor works with HTML, so the quote is prepared in both forms.
  const quoteHeaderHtml =
    `<p>---<br>${escapeHtml(formatDate(original.sent_at || original.received_at || original.created_at))} ` +
    `${escapeHtml(original.from_name || original.from_email || '')} написал(а):</p>`
  const quoteHtml = `<p></p>${quoteHeaderHtml}<blockquote>${originalHtml(original)}</blockquote>`

  const inReplyTo = original.message_id ?? null

  if (mode === 'forward') {
    const base = stripPrefix(original.subject || '', ['Fwd', 'Fw'])
    return {
      mode: 'forward',
      to: [],
      cc: [],
      bcc: [],
      subject: `Fwd: ${base}`,
      body: quote,
      bodyHtml: quoteHtml,
      in_reply_to: null,
    }
  }

  const subject = `Re: ${stripPrefix(original.subject || '', ['Re'])}`

  if (mode === 'reply') {
    return {
      mode: 'reply',
      to: dedupe([original.from_email || ''], ownEmail),
      cc: [],
      bcc: [],
      subject,
      body: quote,
      bodyHtml: quoteHtml,
      in_reply_to: inReplyTo,
    }
  }

  return {
    mode: 'reply_all',
    to: dedupe([original.from_email || '', ...emails(original.to_recipients)], ownEmail),
    cc: dedupe(emails(original.cc_recipients), ownEmail),
    bcc: [],
    subject,
    body: quote,
    bodyHtml: quoteHtml,
    in_reply_to: inReplyTo,
  }
}
