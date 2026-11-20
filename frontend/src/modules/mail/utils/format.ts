/** Human-readable file size, matching the storage module's formatting. */
export function formatBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes <= 0) return '0 Б'
  const units = ['Б', 'КБ', 'МБ', 'ГБ']
  const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  const value = bytes / 1024 ** index
  return `${value.toFixed(index === 0 ? 0 : 1).replace('.', ',')} ${units[index]}`
}

/** Grouped integer with Russian thousand separators. */
export function formatCount(value: number): string {
  return (value || 0).toLocaleString('ru-RU')
}
