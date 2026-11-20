/** Helpers to move between the plain-text form and the editor's HTML. */

export function escapeHtml(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

/**
 * Converts plain text (old drafts, quoted replies) into simple paragraph HTML
 * so it can be opened in the visual editor.
 */
export function textToHtml(text: string): string {
  if (!text || !text.trim()) return ''

  const blocks: string[] = []
  let quoted: string[] = []

  const flushQuote = () => {
    if (quoted.length === 0) return
    blocks.push(`<blockquote><p>${quoted.map(escapeHtml).join('<br>')}</p></blockquote>`)
    quoted = []
  }

  for (const line of text.split(/\r?\n/)) {
    if (/^\s*>/.test(line)) {
      quoted.push(line.replace(/^\s*>\s?/, ''))
      continue
    }
    flushQuote()
    blocks.push(`<p>${line.trim() === '' ? '<br>' : escapeHtml(line)}</p>`)
  }
  flushQuote()

  return blocks.join('')
}

/**
 * Plain-text version of the editor's HTML, sent as the text/plain part of the
 * e-mail and used for the list preview.
 */
export function htmlToText(html: string): string {
  if (!html) return ''

  // Keep line structure: the DOM text content alone would join all blocks.
  const withBreaks = html.replace(/<br\s*\/?>/gi, '\n').replace(/<\/(p|div|li|h[1-6]|blockquote|pre|tr)>/gi, '\n')

  const doc = new DOMParser().parseFromString(withBreaks, 'text/html')

  return (doc.body.textContent || '')
    .replace(/\u00a0/g, ' ')
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
}
