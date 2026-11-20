import { useRef, useState } from 'react'
import { Box, Button, CircularProgress, IconButton, Link, Stack, Tooltip, Typography } from '@mui/material'
import {
  Delete as DeleteIcon,
  Download as DownloadIcon,
  Edit as EditIcon,
  Upload as UploadIcon,
} from '@mui/icons-material'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { crmApi } from '../api'
import type { CrmFile } from '../types'
import CrmOnlyOfficeDialog from './CrmOnlyOfficeDialog'

const OFFICE_EXTENSIONS = [
  'doc',
  'docx',
  'docm',
  'dot',
  'dotx',
  'odt',
  'rtf',
  'txt',
  'xls',
  'xlsx',
  'xlsm',
  'ods',
  'csv',
  'ppt',
  'pptx',
  'pps',
  'ppsx',
  'odp',
]

function isOffice(name: string): boolean {
  const ext = name.split('.').pop()?.toLowerCase() ?? ''
  return OFFICE_EXTENSIONS.includes(ext)
}

function formatSize(bytes: number): string {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)))
  return `${(bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1)} ${units[i]}`
}

export default function CrmFilesPanel({ entity, id }: { entity: string; id: number }) {
  const queryClient = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const [uploading, setUploading] = useState(false)
  const [officeFile, setOfficeFile] = useState<CrmFile | null>(null)

  const { data: files = [], isLoading } = useQuery<CrmFile[]>({
    queryKey: ['crm-files', entity, id],
    queryFn: () => crmApi.files(entity, id),
  })

  const reload = () => queryClient.invalidateQueries({ queryKey: ['crm-files', entity, id] })

  const upload = async (file: File | null) => {
    if (!file) return
    setUploading(true)
    try {
      await crmApi.uploadFile(entity, id, file)
      reload()
    } finally {
      setUploading(false)
      if (inputRef.current) inputRef.current.value = ''
    }
  }

  const detach = async (file: CrmFile) => {
    if (!window.confirm(`Открепить файл «${file.original_name}»?`)) return
    await crmApi.detachFile(entity, id, file.id)
    reload()
  }

  const download = (file: CrmFile) => {
    const token = localStorage.getItem('auth_token') ?? ''
    window.open(`/api/v1/files/${file.id}/download?token=${encodeURIComponent(token)}`, '_blank')
  }

  return (
    <Stack spacing={2}>
      <Box>
        <Button
          variant="outlined"
          startIcon={<UploadIcon />}
          component="label"
          disabled={uploading}
          sx={{ textTransform: 'none' }}
        >
          {uploading ? 'Загрузка...' : 'Загрузить файл'}
          <input ref={inputRef} type="file" hidden onChange={(e) => upload(e.target.files?.[0] ?? null)} />
        </Button>
      </Box>

      {isLoading ? (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 3 }}>
          <CircularProgress size={28} />
        </Box>
      ) : files.length === 0 ? (
        <Typography sx={{ color: 'text.secondary', fontSize: '0.85rem' }}>Файлов пока нет</Typography>
      ) : (
        <Stack spacing={1}>
          {files.map((file) => (
            <Box
              key={file.id}
              sx={{
                display: 'flex',
                alignItems: 'center',
                gap: 1,
                p: 1,
                border: 1,
                borderColor: 'divider',
                borderRadius: 1,
              }}
            >
              <Box sx={{ flex: 1, minWidth: 0 }}>
                <Typography sx={{ fontSize: '0.85rem', fontWeight: 600, wordBreak: 'break-all' }}>
                  {file.original_name}
                </Typography>
                <Typography sx={{ fontSize: '0.75rem', color: 'text.secondary' }}>
                  {formatSize(file.size)}
                  {file.uploader &&
                    ` · ${[file.uploader.first_name, file.uploader.last_name].filter(Boolean).join(' ')}`}
                </Typography>
              </Box>
              {isOffice(file.original_name) && (
                <Tooltip title="Открыть в ONLYOFFICE">
                  <IconButton size="small" onClick={() => setOfficeFile(file)}>
                    <EditIcon fontSize="small" />
                  </IconButton>
                </Tooltip>
              )}
              <Tooltip title="Скачать">
                <IconButton size="small" onClick={() => download(file)}>
                  <DownloadIcon fontSize="small" />
                </IconButton>
              </Tooltip>
              <Tooltip title="Открепить">
                <IconButton size="small" onClick={() => detach(file)}>
                  <DeleteIcon fontSize="small" />
                </IconButton>
              </Tooltip>
            </Box>
          ))}
        </Stack>
      )}

      {officeFile && (
        <CrmOnlyOfficeDialog file={officeFile} open onClose={() => setOfficeFile(null)} onSaved={reload} />
      )}

      <Link component="button" variant="caption" onClick={reload} sx={{ alignSelf: 'flex-start' }}>
        Обновить
      </Link>
    </Stack>
  )
}
