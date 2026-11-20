import { useRef, useState } from 'react'
import { Alert, Box, Button, Chip, Stack, Typography } from '@mui/material'
import { AttachFile as AttachFileIcon } from '@mui/icons-material'
import type { OutgoingAttachment } from '../types'
import { formatBytes } from '../utils/format'

const BLOCKED_EXTENSIONS = [
  'exe',
  'bat',
  'cmd',
  'com',
  'scr',
  'msi',
  'jar',
  'ps1',
  'vbs',
  'jse',
  'wsf',
  'sh',
  'php',
  'phtml',
  'cgi',
  'pl',
  'py',
  'html',
  'htm',
  'svg',
  'hta',
  'lnk',
  'reg',
]

interface AttachmentPickerProps {
  files: OutgoingAttachment[]
  onChange: (files: OutgoingAttachment[]) => void
  maxFiles?: number
  maxFileKb?: number
  maxTotalKb?: number
  disabled?: boolean
}

export default function AttachmentPicker({
  files,
  onChange,
  maxFiles = 10,
  maxFileKb = 10240,
  maxTotalKb = 25600,
  disabled,
}: AttachmentPickerProps) {
  const inputRef = useRef<HTMLInputElement | null>(null)
  const [error, setError] = useState('')

  const total = files.reduce((sum, file) => sum + file.size, 0)

  const handlePick = (event: React.ChangeEvent<HTMLInputElement>) => {
    setError('')
    const picked = Array.from(event.target.files ?? [])
    event.target.value = ''

    const next = [...files]

    for (const file of picked) {
      const extension = file.name.split('.').pop()?.toLowerCase() ?? ''

      if (BLOCKED_EXTENSIONS.includes(extension)) {
        setError(`Файлы с расширением .${extension} нельзя отправлять`)
        continue
      }
      if (next.length >= maxFiles) {
        setError(`К письму можно прикрепить не более ${maxFiles} файлов`)
        break
      }
      if (file.size > maxFileKb * 1024) {
        setError(`Каждый файл должен быть не больше ${maxFileKb / 1024} МБ`)
        continue
      }
      if (next.some((item) => item.file && item.file.name === file.name && item.file.size === file.size)) {
        continue
      }

      next.push({ file, filename: file.name, size: file.size, mime_type: file.type || null })
    }

    if (next.reduce((sum, item) => sum + item.size, 0) > maxTotalKb * 1024) {
      setError(`Общий размер вложений не должен превышать ${maxTotalKb / 1024} МБ`)
    } else {
      onChange(next)
    }
  }

  const remove = (index: number) => {
    setError('')
    onChange(files.filter((_, i) => i !== index))
  }

  return (
    <Box>
      <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
        <Button
          size="small"
          variant="outlined"
          startIcon={<AttachFileIcon />}
          disabled={disabled || files.length >= maxFiles}
          onClick={() => inputRef.current?.click()}
          sx={{ textTransform: 'none' }}
        >
          Прикрепить файл
        </Button>
        <input ref={inputRef} type="file" multiple hidden onChange={handlePick} />
        {files.length > 0 && (
          <Typography variant="caption" color="text.secondary">
            {formatBytes(total)} из {maxTotalKb / 1024} МБ
          </Typography>
        )}
      </Stack>

      {files.length > 0 && (
        <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap sx={{ mt: 1 }}>
          {files.map((file, index) => (
            <Chip
              key={`${file.id ?? 'new'}-${file.filename}-${index}`}
              size="small"
              label={`${file.filename} · ${formatBytes(file.size)}`}
              onDelete={disabled ? undefined : () => remove(index)}
            />
          ))}
        </Stack>
      )}

      {error && (
        <Alert severity="error" sx={{ mt: 1 }} onClose={() => setError('')}>
          {error}
        </Alert>
      )}
    </Box>
  )
}
