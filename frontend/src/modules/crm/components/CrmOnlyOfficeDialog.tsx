import { Dialog, DialogContent, DialogTitle, IconButton, Box } from '@mui/material'
import { Close as CloseIcon } from '@mui/icons-material'
import OnlyOfficeEditor from '@/modules/storage/components/OnlyOfficeEditor'
import type { CrmFile } from '../types'

interface Props {
  file: CrmFile
  open: boolean
  onClose: () => void
  onSaved?: () => void
}

export default function CrmOnlyOfficeDialog({ file, open, onClose, onSaved }: Props) {
  return (
    <Dialog open={open} onClose={onClose} fullScreen>
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1, pr: 6 }}>
        <Box sx={{ flex: 1, fontSize: '1rem' }}>{file.original_name}</Box>
        <IconButton onClick={onClose} size="small">
          <CloseIcon />
        </IconButton>
      </DialogTitle>
      <DialogContent sx={{ p: 0, height: '100%' }}>
        <Box sx={{ width: '100%', height: 'calc(100vh - 64px)' }}>
          <OnlyOfficeEditor configUrl={`/v1/crm/files/${file.id}/office/config`} params={{}} onSaved={onSaved} />
        </Box>
      </DialogContent>
    </Dialog>
  )
}
