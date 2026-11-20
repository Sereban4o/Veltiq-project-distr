import { useState } from 'react'
import { Button, Stack } from '@mui/material'
import { Download as DownloadIcon, Upload as UploadIcon } from '@mui/icons-material'
import { crmApi } from '../api'
import ImportDialog, { type CsvEntity } from './ImportDialog'

interface Props {
  entity: CsvEntity
  params?: Record<string, unknown>
  onDone?: () => void
}

export default function CsvActions({ entity, params = {}, onDone }: Props) {
  const [importOpen, setImportOpen] = useState(false)
  const [exporting, setExporting] = useState(false)

  const exportCsv = async () => {
    setExporting(true)
    try {
      const res = await crmApi.exportCsv(entity, params)
      const blob = new Blob([res.data], { type: 'text/csv;charset=utf-8' })
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `${entity}-${new Date().toISOString().slice(0, 10)}.csv`
      link.click()
      URL.revokeObjectURL(url)
    } finally {
      setExporting(false)
    }
  }

  return (
    <Stack direction="row" spacing={1}>
      <Button
        variant="outlined"
        size="small"
        startIcon={<DownloadIcon />}
        onClick={exportCsv}
        disabled={exporting}
        sx={{ textTransform: 'none' }}
      >
        Экспорт
      </Button>
      <Button
        variant="outlined"
        size="small"
        startIcon={<UploadIcon />}
        onClick={() => setImportOpen(true)}
        sx={{ textTransform: 'none' }}
      >
        Импорт
      </Button>
      <ImportDialog
        entity={entity}
        open={importOpen}
        onClose={() => setImportOpen(false)}
        onDone={() => {
          setImportOpen(false)
          onDone?.()
        }}
      />
    </Stack>
  )
}
