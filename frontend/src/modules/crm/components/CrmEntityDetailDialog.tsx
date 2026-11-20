import { useState, type ReactNode } from 'react'
import { Box, Dialog, DialogContent, DialogTitle, IconButton, Tab, Tabs } from '@mui/material'
import { Close as CloseIcon } from '@mui/icons-material'
import CrmFilesPanel from './CrmFilesPanel'
import CrmEmailsPanel from './CrmEmailsPanel'
import CallLogSection from './CallLogSection'

type Entity = 'deals' | 'companies' | 'contacts'

interface Props {
  entity: Entity
  id: number
  title: string
  open: boolean
  onClose: () => void
  overview?: ReactNode
}

export default function CrmEntityDetailDialog({ entity, id, title, open, onClose, overview }: Props) {
  const [tab, setTab] = useState(0)

  const tabs = [
    { label: 'Обзор', content: overview ?? null },
    { label: 'Файлы', content: <CrmFilesPanel entity={entity} id={id} /> },
    { label: 'Почта', content: <CrmEmailsPanel entity={entity} id={id} /> },
    ...(entity === 'deals' ? [] : [{ label: 'Звонки', content: <CallLogSection entity={entity} id={id} /> }]),
  ]

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1, pr: 6 }}>
        <Box sx={{ flex: 1, fontSize: '1.05rem' }}>{title}</Box>
        <IconButton size="small" onClick={onClose}>
          <CloseIcon />
        </IconButton>
      </DialogTitle>
      <DialogContent>
        <Tabs value={tab} onChange={(_, value) => setTab(value)} sx={{ mb: 2 }} variant="scrollable">
          {tabs.map((item) => (
            <Tab key={item.label} label={item.label} sx={{ textTransform: 'none' }} />
          ))}
        </Tabs>
        {tabs[tab]?.content}
      </DialogContent>
    </Dialog>
  )
}
