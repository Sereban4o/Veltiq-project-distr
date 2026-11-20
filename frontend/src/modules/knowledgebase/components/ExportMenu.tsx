import { useState } from 'react'
import { Button, Menu, MenuItem, ListItemIcon } from '@mui/material'
import { Download as DownloadIcon, PictureAsPdf as PdfIcon, Description as MarkdownIcon } from '@mui/icons-material'
import knowledgebaseApi from '../api/knowledgebaseApi'

interface ExportMenuProps {
  pageId: number
  title: string
  size?: 'small' | 'medium' | 'large'
}

export default function ExportMenu({ pageId, title, size = 'small' }: ExportMenuProps) {
  const [anchorEl, setAnchorEl] = useState<null | HTMLElement>(null)

  const handleExport = async (format: 'pdf' | 'markdown') => {
    setAnchorEl(null)
    try {
      await knowledgebaseApi.downloadExport(pageId, format, title)
    } catch (e) {
      console.error('Export failed', e)
    }
  }

  return (
    <>
      <Button variant="outlined" size={size} startIcon={<DownloadIcon />} onClick={(e) => setAnchorEl(e.currentTarget)}>
        Экспорт
      </Button>
      <Menu anchorEl={anchorEl} open={Boolean(anchorEl)} onClose={() => setAnchorEl(null)}>
        <MenuItem onClick={() => handleExport('pdf')}>
          <ListItemIcon>
            <PdfIcon fontSize="small" />
          </ListItemIcon>
          PDF
        </MenuItem>
        <MenuItem onClick={() => handleExport('markdown')}>
          <ListItemIcon>
            <MarkdownIcon fontSize="small" />
          </ListItemIcon>
          Markdown
        </MenuItem>
      </Menu>
    </>
  )
}
