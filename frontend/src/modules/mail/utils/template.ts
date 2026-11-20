import type { AddressValue } from '../types'

export interface TemplateVars {
  to_name?: string
  my_name?: string
  date?: string
  subject?: string
}

/** Replaces {{key}} / {{ key }} tokens (case-insensitive); unknown keys stay. */
export function renderTemplate(text: string, vars: TemplateVars): string {
  if (!text) return ''

  return text.replace(/\{\{\s*([a-zA-Z_]+)\s*\}\}/g, (match, key: string) => {
    const value = vars[key.toLowerCase() as keyof TemplateVars]
    return value != null ? value : match
  })
}

function recipientName(value: AddressValue): string {
  if (typeof value === 'string') {
    const local = value.split('@')[0]
    return local || value
  }
  return value.name || value.email || ''
}

export function templateVarsFor(opts: { to: AddressValue[]; toEmail?: string; myName?: string | null }): TemplateVars {
  const first = opts.to[0]
  const toName = first != null ? recipientName(first) : opts.toEmail ? recipientName(opts.toEmail) : ''

  return {
    to_name: toName,
    my_name: opts.myName ?? '',
    date: new Date().toLocaleDateString('ru-RU', { day: '2-digit', month: 'long', year: 'numeric' }),
  }
}
