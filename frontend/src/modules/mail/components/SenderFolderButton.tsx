import { useState } from 'react'
import { IconButton, Tooltip } from '@mui/material'
import { CreateNewFolder as SenderFolderIcon } from '@mui/icons-material'
import mailApi from '../api'
import type { FolderFromSenderResult } from '../types'

interface SenderFolderButtonProps {
  senderEmail: string | null
  senderName: string | null
  disabled?: boolean
  onCreated: (result: FolderFromSenderResult) => void
  onError: (message: string) => void
}

export default function SenderFolderButton({
  senderEmail,
  senderName,
  disabled,
  onCreated,
  onError,
}: SenderFolderButtonProps) {
  const [busy, setBusy] = useState(false)

  const handleClick = async () => {
    if (!senderEmail) return

    const suggestedName = (senderName || senderEmail.split('@')[0] || senderEmail).trim()
    if (!window.confirm(`Создать папку «${suggestedName}» и перенести в неё все письма от ${senderEmail}?`)) {
      return
    }

    setBusy(true)
    try {
      const result = await mailApi.folderFromSender(senderEmail)
      onCreated(result)
    } catch (e: any) {
      onError(e?.response?.data?.message || 'Не удалось создать папку для отправителя')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Tooltip title="Создать папку для отправителя">
      <span>
        <IconButton size="small" disabled={disabled || busy || !senderEmail} onClick={handleClick}>
          <SenderFolderIcon fontSize="small" />
        </IconButton>
      </span>
    </Tooltip>
  )
}
