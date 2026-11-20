import { Dialog, DialogContent, DialogTitle, List, ListItemButton, ListItemText, Typography } from '@mui/material'
import { useQuery } from '@tanstack/react-query'
import mailApi from '../api'
import type { MailTemplate } from '../types'

interface TemplatePickerProps {
  open: boolean
  onClose: () => void
  onApply: (template: MailTemplate) => void
}

function preview(html: string): string {
  return html
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 140)
}

export default function TemplatePicker({ open, onClose, onApply }: TemplatePickerProps) {
  const { data: templates = [] } = useQuery<MailTemplate[]>({
    queryKey: ['mail-templates'],
    queryFn: () => mailApi.listTemplates(),
    enabled: open,
  })

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <DialogTitle>Выбрать шаблон</DialogTitle>
      <DialogContent>
        {templates.length === 0 ? (
          <Typography color="text.secondary">Шаблонов пока нет</Typography>
        ) : (
          <List>
            {templates.map((template) => (
              <ListItemButton
                key={template.id}
                onClick={() => {
                  onApply(template)
                  onClose()
                }}
                sx={{ borderRadius: 2, mb: 0.5 }}
              >
                <ListItemText
                  primary={template.name}
                  secondary={
                    <>
                      {template.subject ? (
                        <Typography component="span" sx={{ fontSize: '0.78rem', fontWeight: 600 }}>
                          {template.subject}
                        </Typography>
                      ) : null}
                      <Typography
                        component="span"
                        sx={{ fontSize: '0.78rem', color: 'text.secondary', display: 'block' }}
                      >
                        {preview(template.body_html)}
                      </Typography>
                    </>
                  }
                />
              </ListItemButton>
            ))}
          </List>
        )}
      </DialogContent>
    </Dialog>
  )
}
